<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\DateTime;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Jobs\ApplyDueAssignments;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\Modules\TeamBoard\Tools\Timeline\Service;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use tests\integration\Core\BaseTestCase;

/**
 * F6 (functionality.md sections 1 and 7): a board editor is a trusted top
 * manager. The editor confirms plans and the scheduled job applies them as
 * administrator, even when the editor has only read access to User and Team.
 * Approved model (user decision 2026-10-29): do not require User/Team edit
 * rights for a confirmed plan. Moving a plan to another squad also needs read
 * visibility of the squad it currently belongs to.
 */
class PlanAuthorizationTest extends BaseTestCase
{
    private const ASSIGNMENT = 'TeamBoardAssignment';

    private function em(): EntityManager
    {
        return $this->getContainer()->getByClass(EntityManager::class);
    }

    private function today(): string
    {
        return $this->getContainer()->getByClass(DateTime::class)->getToday()->toString();
    }

    private function shift(int $days): string
    {
        return (new \DateTimeImmutable($this->today()))
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    private function createTeam(string $name): Team
    {
        /** @var Team */
        return $this->em()->createEntity(Team::ENTITY_TYPE, ['name' => $name]);
    }

    private function createSquad(string $name): Entity
    {
        return $this->em()->createEntity('TeamBoardSquad', [
            'name' => $name,
            'positionList' => [Position::MEMBER],
            'isArchived' => false,
        ]);
    }

    /** A CRM user, optionally a member of the given teams with the first one as default. */
    private function createPerson(string $userName, Team ...$teams): User
    {
        $em = $this->em();
        /** @var User $user */
        $user = $em->createEntity(User::ENTITY_TYPE, [
            'userName' => $userName,
            'lastName' => $userName,
            'type' => User::TYPE_REGULAR,
            'isActive' => true,
        ]);

        return $this->joinTeams($user, ...$teams);
    }

    private function joinTeams(User $user, Team ...$teams): User
    {
        $em = $this->em();

        foreach ($teams as $team) {
            $em->getRelation($team, 'users')->relate($user, ['role' => Position::MEMBER]);
        }

        $user = $this->reloadUser($user);

        if ($teams !== []) {
            $user->set(['defaultTeamId' => $teams[0]->getId(), 'defaultTeamName' => $teams[0]->get('name')]);
            $em->saveEntity($user);
        }

        return $this->reloadUser($user);
    }

    private function reloadUser(User $user): User
    {
        return $this->em()->getRDBRepositoryByClass(User::class)->getById($user->getId());
    }

    /**
     * A board editor: TeamBoard read and TeamBoardAssignment edit, CRM rights as given.
     *
     * @param array{read?: string, edit?: string} $user
     * @param array{read?: string, edit?: string} $team
     */
    private function createEditor(string $userName, array $user, array $team): User
    {
        return $this->createUser($userName, ['data' => [
            'TeamBoard' => true,
            'TeamBoardAssignment' => ['read' => 'all', 'edit' => 'all', 'create' => 'yes', 'delete' => 'all'],
            'User' => $user + ['read' => 'all', 'edit' => 'no'],
            'Team' => $team + ['read' => 'all', 'edit' => 'no'],
        ]]);
    }

    private function service(): Service
    {
        return $this->getInjectableFactory()->create(Service::class);
    }

    private function runJob(): void
    {
        $repository = $this->em()->getRDBRepositoryByClass(User::class);

        if (!$repository->where(['type' => User::TYPE_ADMIN, 'isActive' => true])->findOne()) {
            $this->em()->createEntity(User::ENTITY_TYPE, [
                'userName' => 'teamboard.plan.acl.admin',
                'lastName' => 'TeamBoard Plan ACL Admin',
                'type' => User::TYPE_ADMIN,
                'isActive' => true,
            ]);
        }

        $this->getInjectableFactory()->create(ApplyDueAssignments::class)->run();
    }

    private function isMember(Team $team, User $user): bool
    {
        return $this->em()->getRelation($team, 'users')->isRelated($user);
    }

    private function reloadPlan(Entity $plan): Entity
    {
        $fresh = $this->em()->getEntityById(self::ASSIGNMENT, $plan->getId());
        $this->assertNotNull($fresh);

        return $fresh;
    }

    /** @param array<string, mixed> $input */
    private function create(User $member, string $squadOrTeamId, string $status, array $input = []): void
    {
        $this->service()->createAssignment((object) ($input + [
            'memberId' => $member->getId(),
            'teamId' => $squadOrTeamId,
            'position' => Position::MEMBER,
            'dateFrom' => $this->shift(30),
            'dateTo' => null,
            'status' => $status,
        ]));
    }

    /** The editor's own plan row for the member and the given team or board-only squad. */
    private function findPlan(User $member, string $field, string $id): Entity
    {
        $plan = $this->em()->getRDBRepository(self::ASSIGNMENT)
            ->where(['memberId' => $member->getId(), $field => $id])->findOne();
        $this->assertNotNull($plan);

        return $plan;
    }

    /**
     * Editors cannot plan the past, so the system makes a confirmed plan due
     * after the editor call, by moving its start to today.
     */
    private function makeDue(Entity $plan): void
    {
        $plan->set('dateFrom', $this->today());
        $this->em()->saveEntity($plan);
    }

    public function testEditorWithReadOnlyCrmRightsMayStillSaveADraft(): void
    {
        $team = $this->createTeam('Plan ACL draft');
        $member = $this->createPerson('plan.acl.draft.target');
        $editor = $this->createEditor('plan.acl.draft.editor', [], []);
        $this->authenticate($editor->getUserName());

        $this->create($member, $team->getId(), Status::DRAFT);

        $plan = $this->em()->getRDBRepository(self::ASSIGNMENT)
            ->where(['memberId' => $member->getId(), 'teamId' => $team->getId()])->findOne();
        $this->assertNotNull($plan);
        $this->assertSame(Status::DRAFT, $plan->get('status'));
        $this->assertFalse($this->isMember($team, $member));
    }

    /** F6: moving a plan out of a squad the editor cannot see is not allowed. */
    public function testEditorCannotMoveAPlanOutOfAnUnreadableTeam(): void
    {
        $hidden = $this->createTeam('Plan ACL hidden source');
        $visible = $this->createTeam('Plan ACL visible destination');
        $member = $this->createPerson('plan.acl.f6.target');
        $registry = $this->getInjectableFactory()->create(Registry::class);
        $hiddenSquad = $registry->squadForTeam($hidden->getId());
        $plan = $this->getInjectableFactory()->create(AssignmentEditor::class)->createShadow(
            $registry->memberForUser($member->getId())->getId(), $hiddenSquad->getId(),
            $member->getId(), $hidden->getId(), Position::MEMBER,
            $this->shift(30), null, Status::DRAFT, null, Position::DEFAULT_LIST,
        );
        $editor = $this->joinTeams(
            $this->createEditor('plan.acl.f6.editor', [], ['read' => 'team']),
            $visible
        );
        $this->authenticate($editor->getUserName());

        try {
            $this->service()->updateAssignment($plan->getId(), (object) ['teamId' => $visible->getId()]);
            $this->fail('A plan was moved out of a team the editor cannot read.');
        } catch (Forbidden) {
        }

        $fresh = $this->reloadPlan($plan);
        $this->assertSame($hiddenSquad->getId(), $fresh->get('boardSquadId'));
        $this->assertSame($hidden->getId(), $fresh->get('teamId'));
    }

    public function testTrustedEditorsConfirmedFuturePlanIsAppliedByTheJob(): void
    {
        $team = $this->createTeam('Plan ACL trusted team');
        $member = $this->createPerson('plan.acl.trusted.target');
        $editor = $this->createEditor('plan.acl.trusted.editor', [], []);
        $this->authenticate($editor->getUserName());

        // Allowed: User and Team are only read-only for this editor.
        $this->create($member, $team->getId(), Status::CONFIRMED);

        $plan = $this->findPlan($member, 'teamId', $team->getId());
        $this->assertSame(Status::CONFIRMED, $plan->get('status'));
        $this->assertFalse($this->isMember($team, $member));
        $this->makeDue($plan);

        // The scheduled job runs as the system user, not in the editor's session.
        $this->authenticate();
        $this->runJob();

        $fresh = $this->reloadPlan($plan);
        $this->assertSame(Status::CONFIRMED, $fresh->get('status'));
        $this->assertNotNull($fresh->get('appliedAt'));
        $this->assertTrue($this->isMember($team, $member));
        $this->assertSame($team->getId(), $this->reloadUser($member)->get('defaultTeamId'));
    }

    public function testTrustedEditorsConfirmedPlanIntoABoardOnlySquadIsAppliedByTheJob(): void
    {
        $team = $this->createTeam('Plan ACL trusted source');
        $squad = $this->createSquad('Plan ACL trusted board-only cell');
        $member = $this->createPerson('plan.acl.trusted.boardonly.target', $team);
        $editor = $this->createEditor('plan.acl.trusted.boardonly.editor', [], []);
        $this->authenticate($editor->getUserName());

        // Allowed: no CRM Team is created or edited, the squad is board-only.
        $this->create($member, $squad->getId(), Status::CONFIRMED, ['boardSquadId' => $squad->getId()]);

        $plan = $this->findPlan($member, 'boardSquadId', $squad->getId());
        $this->assertSame(Status::CONFIRMED, $plan->get('status'));
        $this->assertTrue($this->isMember($team, $member));
        $this->makeDue($plan);

        // The scheduled job runs as the system user, not in the editor's session.
        $this->authenticate();
        $this->runJob();

        $fresh = $this->reloadPlan($plan);
        $this->assertSame(Status::CONFIRMED, $fresh->get('status'));
        $this->assertNotNull($fresh->get('appliedAt'));
        // Section 1: a board-only squad holds no CRM team membership.
        $this->assertFalse($this->isMember($team, $member));
        $this->assertNull($this->reloadUser($member)->get('defaultTeamId'));
        $this->assertSame(0, $this->em()->getRelation($this->reloadUser($member), User::LINK_TEAMS)->count());
    }
}
