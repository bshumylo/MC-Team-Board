<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Jobs\ApplyDueAssignments;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Shadow\Backfill;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\Modules\TeamBoard\Tools\Timeline\CrmStateRecorder;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use tests\integration\Core\BaseTestCase;

class CrmStateHistoryTest extends BaseTestCase
{
    protected function getEntityManager(): EntityManager
    {
        return $this->getContainer()->getByClass(EntityManager::class);
    }

    private function createMember(string $name): User
    {
        return $this->getEntityManager()->createEntity(User::ENTITY_TYPE, [
            'userName' => $name, 'lastName' => $name, 'type' => User::TYPE_REGULAR, 'isActive' => true,
        ]);
    }

    /** @return Entity[] */
    private function periods(User $user): array
    {
        $member = $this->getInjectableFactory()->create(Registry::class)->memberForUser($user->getId());
        return $this->getInjectableFactory()->create(AssignmentQuery::class)->findForMember($member->getId(), $user->getId());
    }

    public function testNewActiveUserStartsInReserveAndBackfillCanBeRepeated(): void
    {
        $user = $this->createMember('history.new.reserve');
        $rows = $this->periods($user);
        $this->assertCount(1, $rows);
        $this->assertSame(date('Y-m-d'), $rows[0]->get('dateFrom'));
        $this->assertTrue($rows[0]->get('isCrmState'));
        $this->assertTrue($rows[0]->get('crmIsActive'));
        $this->assertNull($rows[0]->get('teamId'));
        $this->assertNotNull($rows[0]->get('appliedAt'));
        $factory = $this->getInjectableFactory();
        $factory->create(Backfill::class)->run();
        $factory->create(CrmStateRecorder::class)->recordForUser($user->getId());
        $factory->create(Backfill::class)->run();
        $this->assertCount(1, $this->periods($user));
    }

    public function testManualDefaultsKeepYesterdayAndOnlyTheFinalStateOfToday(): void
    {
        $em = $this->getEntityManager();
        $factory = $this->getInjectableFactory();
        $user = $this->createMember('history.manual.defaults');
        $teams = [];
        foreach (['A', 'B', 'C'] as $name) {
            $teams[$name] = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'History ' . $name]);
            $em->getRelation($teams[$name], 'users')->relate($user, ['role' => Position::MEMBER]);
        }
        $user->set('defaultTeamId', $teams['A']->getId());
        $em->saveEntity($user);
        $actual = $this->periods($user)[0];
        $yesterday = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $actual->set(['dateFrom' => $yesterday, 'actualDateFrom' => $yesterday]);
        $em->saveEntity($actual); // Fixture: an already recorded previous day.
        $futureDate = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $plan = $factory->create(AssignmentEditor::class)->create(
            $user->getId(), $teams['C']->getId(), Position::MEMBER,
            $futureDate, null, Status::CONFIRMED, null, Position::DEFAULT_LIST
        );

        $user->set('defaultTeamId', $teams['B']->getId());
        $em->saveEntity($user);
        $user->set('defaultTeamId', $teams['C']->getId());
        $em->saveEntity($user);
        $user->set('firstName', 'Profile edit');
        $em->saveEntity($user);

        $yesterdayRow = $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $actual->getId());
        $this->assertSame($yesterday, $yesterdayRow->get('dateFrom'));
        $this->assertSame(date('Y-m-d'), $yesterdayRow->get('dateTo'));
        $this->assertSame($teams['A']->getId(), $yesterdayRow->get('teamId'));
        $this->assertNotNull($yesterdayRow->get('endedAt'));
        $todayRows = array_values(array_filter($this->periods($user), static fn ($row) =>
            $row->get('dateFrom') === date('Y-m-d') && $row->get('appliedAt') !== null));
        $this->assertCount(1, $todayRows);
        $this->assertSame($teams['C']->getId(), $todayRows[0]->get('teamId'));
        $this->assertSame(Status::DRAFT, $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $plan->getId())->get('status'));
        foreach ($teams as $team) {
            $this->assertTrue($em->getRelation($team, 'users')->isRelated($user));
        }
    }

    public function testClearingDefaultAndReactivationDoNotChooseAMembership(): void
    {
        $em = $this->getEntityManager();
        $user = $this->createMember('history.clear.default');
        $team = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'History retained membership']);
        $em->getRelation($team, 'users')->relate($user);
        $user->set('defaultTeamId', $team->getId());
        $em->saveEntity($user);
        $user->set('defaultTeamId', null);
        $em->saveEntity($user);
        $this->assertNull($this->periods($user)[0]->get('teamId'));
        $user->set('isActive', false);
        $em->saveEntity($user);
        $this->assertFalse($this->periods($user)[0]->get('crmIsActive'));
        $user->set('isActive', true);
        $em->saveEntity($user);
        $this->assertCount(1, $this->periods($user));
        $this->assertTrue($this->periods($user)[0]->get('crmIsActive'));
        $this->assertNull($this->periods($user)[0]->get('teamId'));
        $this->assertTrue($em->getRelation($team, 'users')->isRelated($user));
        $this->assertNull($em->getEntityById(User::ENTITY_TYPE, $user->getId())->get('defaultTeamId'));
    }

    public function testAppliedPlanKeepsItsIdentityAndFutureSuccessorAfterSameDayCoalescing(): void
    {
        $em = $this->getEntityManager();
        $factory = $this->getInjectableFactory();
        $em->createEntity(User::ENTITY_TYPE, [
            'userName' => 'history.job.admin', 'type' => User::TYPE_ADMIN, 'isActive' => true,
        ]);
        $user = $this->createMember('history.job.same.day');
        $initial = $this->periods($user)[0];
        $g = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'History scheduled G']);
        $h = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'History later H']);
        $editor = $factory->create(AssignmentEditor::class);
        $first = $editor->create($user->getId(), $g->getId(), Position::MEMBER,
            date('Y-m-d'), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $tomorrow = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        $next = $editor->create($user->getId(), $h->getId(), Position::MEMBER,
            $tomorrow, null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $this->assertSame($first->getId(), $next->get('supersedesId'));

        $factory->create(ApplyDueAssignments::class)->run();
        $factory->create(ApplyDueAssignments::class)->run();
        $rows = $this->periods($user);
        $this->assertCount(2, $rows); // One actual today, one confirmed tomorrow.
        $this->assertNull($em->getEntityById(AssignmentQuery::ENTITY_TYPE, $initial->getId()));
        $actual = $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $first->getId());
        $this->assertNotNull($actual);
        $this->assertSame($g->getId(), $actual->get('teamId'));
        $this->assertSame($tomorrow, $actual->get('dateTo'));
        $this->assertNull($actual->get('supersedesId')); // No reference to the removed same-day fact.
        $this->assertNotNull($actual->get('appliedAt'));
        $this->assertTrue($actual->get('isCrmState'));
        $next = $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $next->getId());
        $this->assertSame($actual->getId(), $next->get('supersedesId'));
        $this->assertSame(Status::CONFIRMED, $next->get('status'));
        $this->assertNull($next->get('appliedAt'));
    }

    public function testUntilCreatesOneFactualReservePeriodAndRetainsEarlierHistory(): void
    {
        $em = $this->getEntityManager();
        $factory = $this->getInjectableFactory();
        $em->createEntity(User::ENTITY_TYPE, [
            'userName' => 'history.until.admin', 'type' => User::TYPE_ADMIN, 'isActive' => true,
        ]);
        $user = $this->createMember('history.until.person');
        $a = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'History until A']);
        $b = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'History retained B']);
        foreach ([$a, $b] as $team) {
            $em->getRelation($team, 'users')->relate($user, ['role' => Position::MEMBER]);
        }
        $user->set('defaultTeamId', $a->getId());
        $em->saveEntity($user);
        $actual = $this->periods($user)[0];
        $yesterday = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
        $actual->set(['dateFrom' => $yesterday, 'actualDateFrom' => $yesterday, 'dateTo' => date('Y-m-d')]);
        $em->saveEntity($actual);

        $factory->create(ApplyDueAssignments::class)->run();
        $factory->create(ApplyDueAssignments::class)->run();
        $rows = $this->periods($user);
        $this->assertCount(2, $rows);
        $this->assertSame($actual->getId(), $rows[0]->getId());
        $this->assertSame($yesterday, $rows[0]->get('dateFrom'));
        $this->assertSame(date('Y-m-d'), $rows[0]->get('dateTo'));
        $this->assertSame($a->getId(), $rows[0]->get('teamId'));
        $this->assertNotNull($rows[0]->get('endedAt'));
        $this->assertSame(date('Y-m-d'), $rows[1]->get('dateFrom'));
        $this->assertNull($rows[1]->get('teamId'));
        $this->assertNull($rows[1]->get('boardSquadId'));
        $this->assertTrue($rows[1]->get('crmIsActive'));
        $this->assertNotNull($rows[1]->get('appliedAt'));
        $fresh = $em->getEntityById(User::ENTITY_TYPE, $user->getId());
        $this->assertNull($fresh->get('defaultTeamId'));
        $this->assertFalse($em->getRelation($a, 'users')->isRelated($fresh));
        $this->assertTrue($em->getRelation($b, 'users')->isRelated($fresh));
    }

    /**
     * T08: a position changed natively in CRM (only the role column of the
     * user's Teams field) is a CRM fact. A later confirmed plan for that
     * exclusive position displaces the new holder, not the former one.
     */
    public function testManualCrmPositionChangeIsRecordedAndDisplacedByALaterPlan(): void
    {
        $em = $this->getEntityManager();
        $team = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'History manual position']);
        $users = [];
        foreach (['p', 'q', 'r'] as $name) {
            $user = $this->createMember('history.manual.position.' . $name);
            $em->getRelation($team, 'users')->relate($user, ['role' => Position::MEMBER]);
            $user = $em->getEntityById(User::ENTITY_TYPE, $user->getId());
            $user->set('defaultTeamId', $team->getId());
            $em->saveEntity($user);
            $users[$name] = $user;
        }
        $setRole = function (User $user, string $role) use ($em, $team): void {
            $fresh = $em->getEntityById(User::ENTITY_TYPE, $user->getId());
            $fresh->loadLinkMultipleField('teams');
            $fresh->set('teamsIds', [$team->getId()]);
            $fresh->set('teamsColumns', (object) [$team->getId() => (object) ['role' => $role]]);
            $em->saveEntity($fresh);
        };
        $setRole($users['p'], Position::LEADER);
        $setRole($users['p'], Position::MEMBER);
        $setRole($users['q'], Position::LEADER);

        $current = static fn (array $rows): array => array_values(array_filter($rows, static fn ($row) =>
            $row->get('status') === Status::CONFIRMED && $row->get('dateTo') === null));
        $this->assertSame(Position::LEADER, $current($this->periods($users['q']))[0]->get('position'));
        $this->assertSame(Position::MEMBER, $current($this->periods($users['p']))[0]->get('position'));

        $future = (new \DateTimeImmutable('+20 days'))->format('Y-m-d');
        $this->getInjectableFactory()->create(AssignmentEditor::class)->create(
            $users['r']->getId(), $team->getId(), Position::LEADER,
            $future, null, Status::CONFIRMED, null, Position::DEFAULT_LIST
        );

        $leadersOn = function (string $date) use ($users): array {
            $holders = [];
            foreach ($users as $name => $user) {
                foreach ($this->periods($user) as $row) {
                    if ($row->get('status') === Status::CONFIRMED &&
                        $row->get('position') === Position::LEADER && $row->get('dateFrom') <= $date &&
                        ($row->get('dateTo') === null || $row->get('dateTo') > $date)) {
                        $holders[] = $name;
                    }
                }
            }
            sort($holders);
            return $holders;
        };
        $this->assertSame(['r'], $leadersOn($future));
        $this->assertSame(['q'], $leadersOn(date('Y-m-d')));
        foreach ($this->periods($users['p']) as $row) {
            $this->assertNotSame(Position::LEADER, $row->get('position'));
        }
        $qTail = array_values(array_filter($this->periods($users['q']), static fn ($row) =>
            $row->get('dateFrom') === $future));
        $this->assertCount(1, $qTail);
        $this->assertSame(Position::MEMBER, $qTail[0]->get('position'));
    }
}
