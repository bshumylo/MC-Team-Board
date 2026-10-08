<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Jobs\ApplyDueAssignments;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use tests\integration\Core\BaseTestCase;

class ApplyDueAssignmentsTest extends BaseTestCase
{
    private const ASSIGNMENT = 'TeamBoardAssignment';

    protected function getEntityManager(): EntityManager
    {
        return $this->getContainer()->getByClass(EntityManager::class);
    }

    private function createTeam(string $name): Team
    {
        /** @var Team */
        return $this->getEntityManager()->createEntity(Team::ENTITY_TYPE, ['name' => $name]);
    }

    private function createMember(string $userName): User
    {
        /** @var User */
        return $this->getEntityManager()->createEntity(User::ENTITY_TYPE, [
            'userName' => $userName,
            'lastName' => $userName,
            'type' => User::TYPE_REGULAR,
            'isActive' => true,
        ]);
    }

    private function createAssignment(User $member, Team $team, string $dateFrom, string $status): Entity
    {
        return $this->getEntityManager()->createEntity(self::ASSIGNMENT, [
            'memberId' => $member->getId(),
            'teamId' => $team->getId(),
            'position' => Position::MEMBER,
            'dateFrom' => $dateFrom,
            'dateTo' => null,
            'status' => $status,
        ]);
    }

    private function isMember(Team $team, User $user): bool
    {
        return $this->getEntityManager()->getRelation($team, 'users')->isRelated($user);
    }

    private function runJob(): void
    {
        $repository = $this->getEntityManager()->getRDBRepositoryByClass(User::class);
        $admin = $repository->where([
            'type' => User::TYPE_ADMIN,
            'isActive' => true,
        ])->findOne();

        if (!$admin) {
            $this->getEntityManager()->createEntity(User::ENTITY_TYPE, [
                'userName' => 'teamboard.test.admin',
                'lastName' => 'TeamBoard Test Admin',
                'type' => User::TYPE_ADMIN,
                'isActive' => true,
            ]);
        }

        $this->getInjectableFactory()->create(ApplyDueAssignments::class)->run();
    }

    public function testAConfirmedDueAssignmentReachesTeamUser(): void
    {
        $team = $this->createTeam('Due');
        $member = $this->createMember('due.one');
        $assignment = $this->createAssignment($member, $team, '2020-01-01', Status::CONFIRMED);

        $this->runJob();

        $this->assertTrue($this->isMember($team, $member));
        $reloadedMember = $this->getEntityManager()->getEntityById(User::ENTITY_TYPE, $member->getId());
        $this->assertSame($team->getId(), $reloadedMember?->get('defaultTeamId'));
        $reloaded = $this->getEntityManager()->getEntityById(self::ASSIGNMENT, $assignment->getId());
        $this->assertNotNull($reloaded);
        $this->assertNotNull($reloaded->get('appliedAt'));
    }

    public function testADraftIsNeverApplied(): void
    {
        $team = $this->createTeam('DueDraft');
        $member = $this->createMember('due.draft');

        $this->createAssignment($member, $team, '2020-01-01', Status::DRAFT);
        $this->runJob();

        $this->assertFalse($this->isMember($team, $member));
    }

    public function testAFutureAssignmentIsNotAppliedYet(): void
    {
        $team = $this->createTeam('DueFuture');
        $member = $this->createMember('due.future');

        $this->createAssignment($member, $team, '2099-01-01', Status::CONFIRMED);
        $this->runJob();

        $this->assertFalse($this->isMember($team, $member));
    }

    public function testTheJobIsIdempotent(): void
    {
        $team = $this->createTeam('Idempotent');
        $member = $this->createMember('idempotent.one');
        $assignment = $this->createAssignment($member, $team, '2020-01-01', Status::CONFIRMED);

        $this->runJob();
        $first = $this->getEntityManager()->getEntityById(self::ASSIGNMENT, $assignment->getId())?->get('appliedAt');

        $this->runJob();
        $second = $this->getEntityManager()->getEntityById(self::ASSIGNMENT, $assignment->getId())?->get('appliedAt');

        $this->assertSame($first, $second);
        $this->assertTrue($this->isMember($team, $member));
    }

    public function testADeletedTeamDoesNotStopTheRestOfTheRun(): void
    {
        $good = $this->createTeam('Survivor');
        $doomed = $this->createTeam('Doomed');
        $one = $this->createMember('survivor.one');
        $two = $this->createMember('doomed.one');

        $this->createAssignment($two, $doomed, '2020-01-01', Status::CONFIRMED);
        $goodAssignment = $this->createAssignment($one, $good, '2020-01-01', Status::CONFIRMED);
        $this->getEntityManager()->removeEntity($doomed);

        $this->runJob();

        $this->assertTrue($this->isMember($good, $one));
        $reloaded = $this->getEntityManager()->getEntityById(self::ASSIGNMENT, $goodAssignment->getId());
        $this->assertNotNull($reloaded?->get('appliedAt'));
    }

    public function testLegacyForbiddenTargetPreservesCrmMembershipsAndDefault(): void
    {
        $oldTeam = $this->createTeam('BoardOnly Old A');
        $otherTeam = $this->createTeam('BoardOnly Old B');
        $member = $this->createMember('board.only.transition');
        $this->getEntityManager()->getRelation($oldTeam, 'users')->relate($member, ['role' => Position::MEMBER]);
        $this->getEntityManager()->getRelation($otherTeam, 'users')->relate($member, ['role' => Position::MEMBER]);
        $member->set([
            'defaultTeamId' => $oldTeam->getId(),
            'defaultTeamName' => $oldTeam->get('name'),
        ]);
        $this->getEntityManager()->saveEntity($member);
        $assignment = $this->getEntityManager()->createEntity(self::ASSIGNMENT, [
            'memberId' => $member->getId(),
            'teamId' => null,
            'position' => Position::MEMBER,
            'dateFrom' => '2020-01-01',
            'dateTo' => null,
            'status' => Status::CONFIRMED,
        ]);

        $this->runJob();

        $this->assertTrue($this->isMember($oldTeam, $member));
        $this->assertTrue($this->isMember($otherTeam, $member));
        $reloaded = $this->getEntityManager()->getEntityById(User::ENTITY_TYPE, $member->getId());
        $this->assertSame($oldTeam->getId(), $reloaded->get('defaultTeamId'));
        $reloadedAssignment = $this->getEntityManager()->getEntityById(
            self::ASSIGNMENT,
            $assignment->getId()
        );
        $this->assertNull($reloadedAssignment?->get('appliedAt'));
    }

    public function testConfirmedMoveReplacesOnlyDefaultMembershipAndPreservesLaterPlan(): void
    {
        $oldA = $this->createTeam('Transition Old A');
        $oldB = $this->createTeam('Transition Old B');
        $oldC = $this->createTeam('Transition Old C');
        $newG = $this->createTeam('Transition New G');
        $member = $this->createMember('confirmed.default.move');

        foreach ([$oldA, $oldB, $oldC] as $team) {
            $this->getEntityManager()->getRelation($team, 'users')
                ->relate($member, ['role' => Position::MEMBER]);
        }

        $member->set([
            'defaultTeamId' => $oldA->getId(),
            'defaultTeamName' => $oldA->get('name'),
        ]);
        $this->getEntityManager()->saveEntity($member);

        $crm = $this->createAssignment($member, $newG, '2020-01-01', Status::CONFIRMED);
        $future = $this->createAssignment($member, $oldB, '2099-01-01', Status::CONFIRMED);

        $this->runJob();

        $this->assertFalse($this->isMember($oldA, $member));
        $this->assertTrue($this->isMember($oldB, $member));
        $this->assertTrue($this->isMember($oldC, $member));
        $this->assertTrue($this->isMember($newG, $member));
        $afterCrm = $this->getEntityManager()->getEntityById(User::ENTITY_TYPE, $member->getId());
        $this->assertEqualsCanonicalizing([$oldB->getId(), $oldC->getId(), $newG->getId()], $afterCrm->getTeamIdList());
        $this->assertSame($newG->getId(), $afterCrm->get('defaultTeamId'));
        $this->assertSame($newG->get('name'), $afterCrm->get('defaultTeamName'));
        $this->assertNotNull($this->getEntityManager()->getEntityById(self::ASSIGNMENT, $crm->getId())?->get('appliedAt'));
        $this->assertSame(Status::CONFIRMED,
            $this->getEntityManager()->getEntityById(self::ASSIGNMENT, $future->getId())?->get('status'));
    }

    public function testManualDefaultAndActivationChangesRequireNewConfirmation(): void
    {
        $em = $this->getEntityManager();
        $team = $this->createTeam('Manual CRM state');
        $otherTeam = $this->createTeam('Manual CRM destination');
        $member = $this->createMember('manual.crm.state');
        $otherMember = $this->createMember('manual.crm.unaffected');
        foreach ([$team, $otherTeam] as $membership) {
            $em->getRelation($membership, 'users')->relate($member);
        }
        $member->set('defaultTeamId', $team->getId());
        $em->saveEntity($member);
        $future = $this->createAssignment($member, $otherTeam, '2099-01-01', Status::CONFIRMED);
        $otherFuture = $this->createAssignment($otherMember, $team, '2099-01-01', Status::CONFIRMED);
        $member->set('firstName', 'Updated profile');
        $em->saveEntity($member);
        $this->assertSame(Status::CONFIRMED, $em->getEntityById(self::ASSIGNMENT, $future->getId())?->get('status'));

        $member->set('defaultTeamId', $otherTeam->getId());
        $em->saveEntity($member);
        $this->assertSame(Status::DRAFT, $em->getEntityById(self::ASSIGNMENT, $future->getId())?->get('status'));
        $this->assertSame(Status::CONFIRMED, $em->getEntityById(self::ASSIGNMENT, $otherFuture->getId())?->get('status'));

        $late = $this->createAssignment($member, $team, '2020-01-01', Status::CONFIRMED);
        $member->set('isActive', false);
        $em->saveEntity($member);
        $this->assertSame(Status::DRAFT, $em->getEntityById(self::ASSIGNMENT, $late->getId())?->get('status'));
        $member->set('isActive', true);
        $em->saveEntity($member);
        $this->runJob();
        $this->assertSame($otherTeam->getId(), $em->getEntityById(User::ENTITY_TYPE, $member->getId())?->get('defaultTeamId'));
        $this->assertNull($em->getEntityById(self::ASSIGNMENT, $late->getId())?->get('appliedAt'));
        $this->assertSame(Status::DRAFT, $em->getEntityById(self::ASSIGNMENT, $future->getId())?->get('status'));
    }

    public function testUntilClearsDefaultOnceAndPreservesLaterManualMembership(): void
    {
        $em = $this->getEntityManager();
        $team = $this->createTeam('Until default');
        $otherTeam = $this->createTeam('Until other membership');
        $member = $this->createMember('until.once');
        $em->getRelation($otherTeam, 'users')->relate($member);
        $assignment = $this->createAssignment($member, $team, '2020-01-01', Status::CONFIRMED);
        $this->runJob();
        $assignment = $em->getEntityById(self::ASSIGNMENT, $assignment->getId());
        // Fixture: an actual membership already present on the previous day.
        // Same-day entry/exit is deliberately coalesced to the final Reserve.
        $assignment->set([
            'dateTo' => date('Y-m-d'),
            'actualDateFrom' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
        ]);
        $em->saveEntity($assignment);
        $this->runJob();
        $member = $em->getEntityById(User::ENTITY_TYPE, $member->getId());
        $this->assertNull($member->get('defaultTeamId'));
        $this->assertFalse($this->isMember($team, $member));
        $this->assertTrue($this->isMember($otherTeam, $member));
        $this->assertNotNull($em->getEntityById(self::ASSIGNMENT, $assignment->getId())?->get('endedAt'));

        $em->getRelation($team, 'users')->relate($member);
        $member->set('defaultTeamId', $team->getId());
        $em->saveEntity($member);
        $this->runJob();
        $this->assertTrue($this->isMember($team, $member));
        $this->assertSame($team->getId(), $em->getEntityById(User::ENTITY_TYPE, $member->getId())?->get('defaultTeamId'));
    }

    public function testAManualCrmChangeReturnsADisplacementTailToDraftSoTheJobKeepsTheManualState(): void
    {
        $em = $this->getEntityManager();
        $b = $this->createTeam('T23 tail B');
        $c = $this->createTeam('T23 tail C');
        $incumbent = $this->createMember('t23.incumbent');
        $successor = $this->createMember('t23.successor');
        $editor = $this->getInjectableFactory()->create(AssignmentEditor::class);
        $day = static fn (int $n): string => (new \DateTimeImmutable("+$n days"))->format('Y-m-d');

        $editor->create($incumbent->getId(), $b->getId(), Position::LEADER, $day(3), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $editor->create($successor->getId(), $b->getId(), Position::LEADER, $day(6), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $tail = $em->getRDBRepository(self::ASSIGNMENT)->where([
            'memberId' => $incumbent->getId(), 'dateFrom' => $day(6),
        ])->findOne();
        $this->assertNotNull($tail);
        $this->assertNotNull($tail->get('displacedById'));

        $em->getRelation($c, 'users')->relate($incumbent, ['role' => Position::MEMBER]);
        $incumbent->set('defaultTeamId', $c->getId());
        $em->saveEntity($incumbent);

        foreach ($em->getRDBRepository(self::ASSIGNMENT)->where([
            'memberId' => $incumbent->getId(), 'status' => Status::CONFIRMED, 'appliedAt' => null,
        ])->find() as $row) {
            $this->fail('A confirmed future transition survived the manual CRM change: ' .
                $row->get('position') . ' ' . $row->get('dateFrom'));
        }

        // Even once due, the job never overrides the manual state with the tail.
        $em->getQueryExecutor()->execute(
            $em->getQueryBuilder()->update()->in(self::ASSIGNMENT)
                ->set(['dateFrom' => '2020-01-01'])->where(['id' => $tail->getId()])->build()
        );
        $this->runJob();
        $this->assertSame($c->getId(), $em->getEntityById(User::ENTITY_TYPE, $incumbent->getId())?->get('defaultTeamId'));
        $this->assertNull($em->getEntityById(self::ASSIGNMENT, $tail->getId())?->get('appliedAt'));
    }
}
