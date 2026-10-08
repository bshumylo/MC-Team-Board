<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\DateTime;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Jobs\ApplyDueAssignments;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Timeline\Service;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;
use tests\integration\Core\BaseTestCase;

/**
 * D8-C1 — functionality.md section 1, T06, T16, T20, T22, T23, section 8:
 * a CRM user may be assigned to a board-only team.
 */
class CrmUserInBoardOnlyTeamTest extends BaseTestCase
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

    private function shift(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
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
            'positionList' => ['Lead', 'Dev'],
            'isArchived' => false,
        ]);
    }

    /** A CRM user with memberships A and B, default A. */
    private function createUserInTeams(string $userName, Team $default, Team ...$others): User
    {
        $em = $this->em();
        /** @var User $user */
        $user = $em->createEntity(User::ENTITY_TYPE, [
            'userName' => $userName,
            'lastName' => $userName,
            'type' => User::TYPE_REGULAR,
            'isActive' => true,
        ]);
        foreach ([$default, ...$others] as $team) {
            $em->getRelation($team, 'users')->relate($user, ['role' => Position::MEMBER]);
        }
        $user = $em->getRDBRepositoryByClass(User::class)->getById($user->getId());
        $user->set(['defaultTeamId' => $default->getId(), 'defaultTeamName' => $default->get('name')]);
        $em->saveEntity($user);

        return $em->getRDBRepositoryByClass(User::class)->getById($user->getId());
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
                'userName' => 'teamboard.c1.admin',
                'lastName' => 'TeamBoard C1 Admin',
                'type' => User::TYPE_ADMIN,
                'isActive' => true,
            ]);
        }
        $this->getInjectableFactory()->create(ApplyDueAssignments::class)->run();
    }

    private function reloadUser(User $user): User
    {
        return $this->em()->getRDBRepositoryByClass(User::class)->getById($user->getId());
    }

    private function assign(User $user, Entity $squad, string $dateFrom, ?string $dateTo, string $status): Entity
    {
        $this->service()->createAssignment((object) [
            'memberId' => $user->getId(),
            'boardSquadId' => $squad->getId(),
            'position' => 'Dev',
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'status' => $status,
        ]);

        $plan = $this->em()->getRDBRepository(self::ASSIGNMENT)->where([
            'memberId' => $user->getId(),
            'boardSquadId' => $squad->getId(),
        ])->order('createdAt', 'DESC')->findOne();
        $this->assertNotNull($plan);

        return $plan;
    }

    /** @return string[] */
    private function squadMembers(stdClass $payload, Entity $squad): array
    {
        foreach ($payload->teams as $team) {
            if ($team->boardSquadId === $squad->getId()) {
                return array_map(fn (stdClass $entry) => $entry->id, $team->members);
            }
        }

        return [];
    }

    /** @return string[] */
    private function reserveIds(stdClass $payload): array
    {
        return array_map(fn (stdClass $entry) => $entry->id, $payload->reserve);
    }

    private function timeline(string $date): stdClass
    {
        return $this->service()->getTimeline($date, $this->shift($date, -30), $this->shift($date, 30));
    }

    public function testConfirmedAssignmentRemovesAllCrmMembershipsAndDefaultWhenApplied(): void
    {
        $alpha = $this->createTeam('C1 Alpha');
        $bravo = $this->createTeam('C1 Bravo');
        $squad = $this->createSquad('C1 Board cell');
        $user = $this->createUserInTeams('c1.apply', $alpha, $bravo);
        $today = $this->today();

        $plan = $this->assign($user, $squad, $today, null, Status::CONFIRMED);
        $this->runJob();

        $user = $this->reloadUser($user);
        $this->assertSame([], $user->getTeamIdList());
        $this->assertNull($user->get('defaultTeamId'));
        $plan = $this->em()->getEntityById(self::ASSIGNMENT, $plan->getId());
        $this->assertNotNull($plan->get('appliedAt'));
        $this->assertSame($squad->getId(), $plan->get('boardSquadId'));
        $this->assertSame('Dev', $plan->get('position'));

        $payload = $this->timeline($today);
        $this->assertSame([$user->getId()], $this->squadMembers($payload, $squad));
        $this->assertNotContains($user->getId(), $this->reserveIds($payload));

        // A repeated run changes nothing.
        $this->runJob();
        $this->assertSame([$user->getId()], $this->squadMembers($this->timeline($today), $squad));
    }

    public function testUntilWithoutSuccessorMovesThePersonToReserve(): void
    {
        $alpha = $this->createTeam('C1 Until Alpha');
        $squad = $this->createSquad('C1 Until cell');
        $user = $this->createUserInTeams('c1.until', $alpha);
        $today = $this->today();
        $until = $this->shift($today, 10);

        $plan = $this->assign($user, $squad, $today, $until, Status::CONFIRMED);
        $this->runJob();

        // Projection before the Until date: still in the board-only team.
        $before = $this->timeline($this->shift($today, 5));
        $this->assertSame([$user->getId()], $this->squadMembers($before, $squad));
        // Projection on the Until date: Reserve.
        $after = $this->timeline($until);
        $this->assertSame([], $this->squadMembers($after, $squad));
        $this->assertContains($user->getId(), $this->reserveIds($after));

        // The Until date arrives (fixture: the actual period started yesterday).
        $em = $this->em();
        $plan = $em->getEntityById(self::ASSIGNMENT, $plan->getId());
        $plan->set(['dateTo' => $today, 'actualDateFrom' => $this->shift($today, -1)]);
        $em->saveEntity($plan);
        $this->runJob();

        $plan = $em->getEntityById(self::ASSIGNMENT, $plan->getId());
        $this->assertNotNull($plan->get('endedAt'));
        $this->assertSame($today, $plan->get('actualDateTo'));
        $payload = $this->timeline($today);
        $this->assertSame([], $this->squadMembers($payload, $squad));
        $this->assertContains($user->getId(), $this->reserveIds($payload));
        $this->assertSame([], $this->reloadUser($user)->getTeamIdList());
    }

    public function testManualCrmDefaultClosesTheBoardOnlyAssignment(): void
    {
        $alpha = $this->createTeam('C1 Manual Alpha');
        $charlie = $this->createTeam('C1 Manual Charlie');
        $squad = $this->createSquad('C1 Manual cell');
        $user = $this->createUserInTeams('c1.manual', $alpha);
        $today = $this->today();
        $em = $this->em();

        $plan = $this->assign($user, $squad, $today, null, Status::CONFIRMED);
        $this->runJob();
        $plan = $em->getEntityById(self::ASSIGNMENT, $plan->getId());
        $plan->set(['dateFrom' => $this->shift($today, -3), 'actualDateFrom' => $this->shift($today, -3)]);
        $em->saveEntity($plan);
        $future = $this->assign($user, $this->createSquad('C1 Manual later'), $this->shift($today, 20), null,
            Status::CONFIRMED);

        // A membership without a default does not end the assignment (T16).
        $em->getRelation($charlie, 'users')->relate($this->reloadUser($user));
        $this->assertSame([$user->getId()], $this->squadMembers($this->timeline($today), $squad));
        $this->assertNull($em->getEntityById(self::ASSIGNMENT, $plan->getId())->get('actualDateTo'));

        // A manual default closes it from the date of the change (T22) and
        // returns later confirmed plans to Draft (T23).
        $user = $this->reloadUser($user);
        $user->set(['defaultTeamId' => $charlie->getId(), 'defaultTeamName' => $charlie->get('name')]);
        $em->saveEntity($user);

        $plan = $em->getEntityById(self::ASSIGNMENT, $plan->getId());
        $this->assertSame($today, $plan->get('actualDateTo'));
        $this->assertSame(Status::DRAFT, $em->getEntityById(self::ASSIGNMENT, $future->getId())->get('status'));
        $payload = $this->timeline($today);
        $this->assertSame([], $this->squadMembers($payload, $squad));
        $charlieIds = [];
        foreach ($payload->teams as $team) {
            if ($team->teamId === $charlie->getId()) {
                $charlieIds = array_map(fn (stdClass $entry) => $entry->id, $team->members);
            }
        }
        $this->assertSame([$user->getId()], $charlieIds);

        $this->runJob();
        $user = $this->reloadUser($user);
        $this->assertSame($charlie->getId(), $user->get('defaultTeamId'));
        $this->assertContains($charlie->getId(), $user->getTeamIdList());
    }

    public function testDraftHasNoCrmEffect(): void
    {
        $alpha = $this->createTeam('C1 Draft Alpha');
        $bravo = $this->createTeam('C1 Draft Bravo');
        $squad = $this->createSquad('C1 Draft cell');
        $user = $this->createUserInTeams('c1.draft', $alpha, $bravo);
        $today = $this->today();

        $plan = $this->assign($user, $squad, $today, null, Status::DRAFT);
        $this->runJob();

        $user = $this->reloadUser($user);
        $this->assertEqualsCanonicalizing([$alpha->getId(), $bravo->getId()], $user->getTeamIdList());
        $this->assertSame($alpha->getId(), $user->get('defaultTeamId'));
        $this->assertNull($this->em()->getEntityById(self::ASSIGNMENT, $plan->getId())->get('appliedAt'));
        // U06 (2026-09-25): the Draft in effect today is shown at its
        // destination (pale), while CRM stays unchanged (T05).
        $shown = [];
        foreach ($this->timeline($today)->teams as $team) {
            if ($team->boardSquadId === $squad->getId()) {
                $shown = array_map(fn (stdClass $entry) => $entry->status, $team->members);
            }
        }
        $this->assertSame([Status::DRAFT], $shown);
    }

    /** Open confirmed periods of the user that still cover today (actual view). */
    private function openConfirmed(User $user): int
    {
        $today = $this->today();
        $count = 0;
        foreach ($this->em()->getRDBRepository(self::ASSIGNMENT)->where([
            'memberId' => $user->getId(),
            'status' => Status::CONFIRMED,
        ])->find() as $row) {
            $start = (string) ($row->get('actualDateFrom') ?: $row->get('dateFrom'));
            $end = $row->get('actualDateTo') ?? $row->get('dateTo');
            if ($start <= $today && ($end === null || $end > $today)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Verifier round 1: an admin's confirmed assignment starting today must
     * take effect on that day, not when the scheduled job next runs (T06,
     * T16, T20, section 1).
     */
    public function testSameDayConfirmedAssignmentTakesEffectWithoutTheJob(): void
    {
        $alpha = $this->createTeam('C1 Now Alpha');
        $bravo = $this->createTeam('C1 Now Bravo');
        $squad = $this->createSquad('C1 Now cell');
        $user = $this->createUserInTeams('c1.now', $alpha, $bravo);
        $today = $this->today();

        $plan = $this->assign($user, $squad, $today, null, Status::CONFIRMED);

        $user = $this->reloadUser($user);
        $this->assertSame([], $user->getTeamIdList());
        $this->assertNull($user->get('defaultTeamId'));
        $this->assertNotNull($this->em()->getEntityById(self::ASSIGNMENT, $plan->getId())->get('appliedAt'));
        $payload = $this->timeline($today);
        $this->assertSame([$user->getId()], $this->squadMembers($payload, $squad));
        $this->assertNotContains($user->getId(), $this->reserveIds($payload));
        $this->assertSame(1, $this->openConfirmed($user));

        // The job afterwards changes nothing.
        $this->runJob();
        $this->assertSame([], $this->reloadUser($user)->getTeamIdList());
        $this->assertSame([$user->getId()], $this->squadMembers($this->timeline($today), $squad));
        $this->assertSame(1, $this->openConfirmed($user));
    }

    public function testDraftConfirmedTodayTakesEffectWithoutTheJob(): void
    {
        $alpha = $this->createTeam('C1 Confirm Alpha');
        $squad = $this->createSquad('C1 Confirm cell');
        $user = $this->createUserInTeams('c1.confirm', $alpha);
        $today = $this->today();

        $plan = $this->assign($user, $squad, $today, null, Status::DRAFT);
        $this->assertSame($alpha->getId(), $this->reloadUser($user)->get('defaultTeamId'));

        $this->service()->updateAssignment($plan->getId(), (object) ['status' => Status::CONFIRMED]);

        $user = $this->reloadUser($user);
        $this->assertSame([], $user->getTeamIdList());
        $this->assertNull($user->get('defaultTeamId'));
        $payload = $this->timeline($today);
        $this->assertSame([$user->getId()], $this->squadMembers($payload, $squad));
        $this->assertNotContains($user->getId(), $this->reserveIds($payload));
        $this->assertSame(1, $this->openConfirmed($user));
    }

    /**
     * D8-A fix-A — a CRM user's board-only assignment opened today and dragged
     * to Reserve the same day (closingSameDay, T16/T20) must still record the
     * resulting Reserve fact, exactly as endLinkedMembershipNow() does for a
     * period that ends on schedule. There is no CRM team relation to unrelate
     * here (teamId is null, only boardSquadId is set), so no
     * Hooks\Team\ResetFuturePlans fires to record it — the Service itself must.
     */
    public function testDraggingASameDayBoardOnlyAssignmentToReserveRecordsTheReserveFact(): void
    {
        $alpha = $this->createTeam('C1 Reserve Alpha');
        $squad = $this->createSquad('C1 Reserve cell');
        $user = $this->createUserInTeams('c1.reserve.sameday', $alpha);
        $today = $this->today();
        $em = $this->em();

        $plan = $this->assign($user, $squad, $today, null, Status::CONFIRMED);

        // Applied immediately (no job run needed): the plan row itself becomes
        // the isCrmState fact (board-only, no CRM team behind it).
        $plan = $em->getEntityById(self::ASSIGNMENT, $plan->getId());
        $this->assertTrue((bool) $plan->get('isCrmState'));
        $this->assertNull($plan->get('teamId'));
        $this->assertSame($squad->getId(), $plan->get('boardSquadId'));
        $this->assertSame([], $this->reloadUser($user)->getTeamIdList());

        $service = $this->service();
        $payload = $service->updateAssignment($plan->getId(), (object) [
            'dateTo' => $today,
            'viewDate' => $today,
        ]);

        // The zero-length window removes this row entirely.
        $this->assertNull($em->getEntityById(self::ASSIGNMENT, $plan->getId()));
        $this->assertSame([], $this->squadMembers($payload, $squad));

        // The Reserve transition must be recorded, not silently dropped: a
        // fresh isCrmState fact for this user, in effect today, with no team
        // and no board squad.
        $facts = $em->getRDBRepository(self::ASSIGNMENT)->where([
            'memberId' => $user->getId(),
            'isCrmState' => true,
        ])->find();
        $reserveFact = null;
        foreach ($facts as $fact) {
            if ($fact->get('teamId') === null && $fact->get('boardSquadId') === null &&
                $fact->get('actualDateFrom') === $today && $fact->get('actualDateTo') === null) {
                $reserveFact = $fact;
            }
        }
        $this->assertNotNull($reserveFact, 'Expected a Reserve isCrmState fact recorded for today.');
        $this->assertContains($user->getId(), $this->reserveIds($payload));
    }

    public function testEditorMayPlanOnlyFutureDates(): void
    {
        $alpha = $this->createTeam('C1 Editor Alpha');
        $squad = $this->createSquad('C1 Editor cell');
        $user = $this->createUserInTeams('c1.editor.target', $alpha);
        $editor = $this->createUser('c1.editor', ['data' => [
            'TeamBoard' => true,
            'TeamBoardAssignment' => ['read' => 'all', 'edit' => 'all', 'create' => 'yes', 'delete' => 'all'],
            'Team' => ['read' => 'all', 'edit' => 'no'],
            'User' => ['read' => 'all', 'edit' => 'no'],
        ]]);
        $today = $this->today();
        $this->authenticate($editor->getUserName());

        try {
            $this->assign($user, $squad, $today, null, Status::CONFIRMED);
            $this->fail('An editor changed a current placement.');
        } catch (Forbidden) {
        }
        $this->assertSame(0, $this->em()->getRDBRepository(self::ASSIGNMENT)
            ->where(['memberId' => $user->getId(), 'boardSquadId' => $squad->getId()])->count());

        $plan = $this->assign($user, $squad, $this->shift($today, 7), null, Status::CONFIRMED);
        $this->assertSame(Status::CONFIRMED, $plan->get('status'));
        $user = $this->reloadUser($user);
        $this->assertSame($alpha->getId(), $user->get('defaultTeamId'));
    }
}
