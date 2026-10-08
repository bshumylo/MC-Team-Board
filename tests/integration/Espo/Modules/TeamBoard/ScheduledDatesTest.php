<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Jobs\ApplyDueAssignments;
use Espo\Modules\TeamBoard\Jobs\CleanupMissedDrafts;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\Modules\TeamBoard\Tools\Timeline\Service;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use tests\integration\Core\BaseTestCase;

/** Actual daily history cannot be backdated by delayed scheduler execution. */
class ScheduledDatesTest extends BaseTestCase
{
    private function day(string $offset = 'today'): string
    {
        return (new \DateTimeImmutable($offset))->format('Y-m-d');
    }

    private function fixtures(): array
    {
        $em = $this->getEntityManager();
        $em->createEntity(User::ENTITY_TYPE, [
            'userName' => 'scheduled.dates.admin', 'type' => User::TYPE_ADMIN, 'isActive' => true,
        ]);
        $user = $em->createEntity(User::ENTITY_TYPE, [
            'userName' => 'scheduled.dates.person', 'type' => User::TYPE_REGULAR, 'isActive' => true,
        ]);
        $a = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'Scheduled actual A']);
        $g = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'Scheduled destination G']);
        $em->getRelation($a, 'users')->relate($user, ['role' => Position::MEMBER]);
        $user->set('defaultTeamId', $a->getId());
        $em->saveEntity($user);
        $actual = $em->getRDBRepository(AssignmentQuery::ENTITY_TYPE)->where([
            'memberId' => $user->getId(), 'isCrmState' => true,
        ])->findOne();
        $actual->set(['dateFrom' => $this->day('-4 days'), 'actualDateFrom' => $this->day('-4 days')]);
        $em->saveEntity($actual); // A recorded fact predating the planned transition.
        return [$user, $a, $g, $actual];
    }

    private function locations(User $user, string $offset): array
    {
        $board = $this->getInjectableFactory()->create(Service::class)
            ->getTimeline($this->day($offset), $this->day('-1 month'), $this->day('+1 month'));
        $locations = [];
        foreach ($board->teams as $team) {
            foreach ($team->members as $member) {
                if ($member->userId === $user->getId() && $member->status === Status::CONFIRMED) {
                    $locations[] = $team->teamId;
                }
            }
        }
        if (in_array($user->getId(), array_column($board->reserve, 'userId'), true)) {
            $locations[] = 'reserve';
        }
        return $locations;
    }

    public function testPendingOverdueTransitionDoesNotEraseActualPredecessorFromPastDays(): void
    {
        [$user, $a, $g] = $this->fixtures();
        $this->getInjectableFactory()->create(AssignmentEditor::class)->create(
            $user->getId(), $g->getId(), Position::MEMBER, $this->day('-2 days'),
            null, Status::CONFIRMED, null, Position::DEFAULT_LIST,
        );
        $this->assertSame([$a->getId()], $this->locations($user, '-1 day'));
        $this->assertSame([$a->getId()], $this->locations($user, 'today'));
    }

    public function testPendingOverdueTransitionDoesNotProjectAnUnrequestedReserveExit(): void
    {
        [$user, $a, $g] = $this->fixtures();
        $this->getInjectableFactory()->create(AssignmentEditor::class)->create(
            $user->getId(), $g->getId(), Position::MEMBER, $this->day('-2 days'),
            null, Status::CONFIRMED, null, Position::DEFAULT_LIST,
        );
        $this->assertSame([$a->getId()], $this->locations($user, '+1 day'));
    }

    public function testCompletelyMissedConfirmedWindowLeavesActualCrmStateAndHistoryIntact(): void
    {
        [$user, $a, $g, $actual] = $this->fixtures();
        $em = $this->getEntityManager();
        $factory = $this->getInjectableFactory();
        $plan = $factory->create(AssignmentEditor::class)->create(
            $user->getId(), $g->getId(), Position::MEMBER, $this->day('-2 days'),
            $this->day('-1 day'), Status::CONFIRMED, null, Position::DEFAULT_LIST,
        );
        $factory->create(ApplyDueAssignments::class)->run();
        $factory->create(ApplyDueAssignments::class)->run();
        $fresh = $em->getEntityById(User::ENTITY_TYPE, $user->getId());
        $this->assertSame($a->getId(), $fresh->get('defaultTeamId'));
        $this->assertTrue($em->getRelation($a, 'users')->isRelated($fresh));
        $this->assertFalse($em->getRelation($g, 'users')->isRelated($fresh));
        foreach (['-1 day', 'today', '+1 day'] as $offset) {
            $this->assertSame([$a->getId()], $this->locations($user, $offset));
        }
        $plan = $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $plan->getId());
        $this->assertNotNull($plan);
        // The window can never be applied, so the plan does not stay confirmed
        // for ever; it returns to Draft and T11 removes it the next day.
        $this->assertSame(Status::DRAFT, $plan->get('status'));
        $this->assertNull($plan->get('appliedAt'));
        $this->assertNull($plan->get('actualDateFrom'));
        $actual = $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $actual->getId());
        $this->assertNull($actual->get('endedAt'));
        $this->assertNull($actual->get('actualDateTo'));
    }

    public function testCompletelyMissedConfirmedWindowRestoresThePredecessorBoundary(): void
    {
        [$user, $a, $g, $actual] = $this->fixtures();
        $em = $this->getEntityManager();
        $factory = $this->getInjectableFactory();
        $factory->create(AssignmentEditor::class)->create(
            $user->getId(), $g->getId(), Position::MEMBER, $this->day('-2 days'),
            $this->day('-1 day'), Status::CONFIRMED, null, Position::DEFAULT_LIST,
        );
        $closed = $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $actual->getId());
        $this->assertSame($this->day('-2 days'), $closed->get('dateTo'));

        $factory->create(ApplyDueAssignments::class)->run();

        $reopened = $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $actual->getId());
        $this->assertNull($reopened->get('dateTo'));
        $this->assertNull($reopened->get('endedAt'));
    }

    public function testExpiredPlanIsThenRemovedByTheMissedDraftJob(): void
    {
        [$user, $a, $g, $actual] = $this->fixtures();
        $em = $this->getEntityManager();
        $factory = $this->getInjectableFactory();
        $plan = $factory->create(AssignmentEditor::class)->create(
            $user->getId(), $g->getId(), Position::MEMBER, $this->day('-2 days'),
            $this->day('-1 day'), Status::CONFIRMED, null, Position::DEFAULT_LIST,
        );

        $factory->create(ApplyDueAssignments::class)->run();
        $factory->create(CleanupMissedDrafts::class)->run();

        $this->assertNull($em->getEntityById(AssignmentQuery::ENTITY_TYPE, $plan->getId()));
        $survivor = $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $actual->getId());
        $this->assertNotNull($survivor);
        $this->assertNull($survivor->get('dateTo'));
        $this->assertSame([$a->getId()], $this->locations($user, 'today'));
    }

    public function testExpiringAnUnappliedWindowIsIdempotent(): void
    {
        [$user, $a, $g, $actual] = $this->fixtures();
        $em = $this->getEntityManager();
        $factory = $this->getInjectableFactory();
        $plan = $factory->create(AssignmentEditor::class)->create(
            $user->getId(), $g->getId(), Position::MEMBER, $this->day('-2 days'),
            $this->day('-1 day'), Status::CONFIRMED, null, Position::DEFAULT_LIST,
        );

        foreach ([1, 2, 3] as $ignored) {
            $factory->create(ApplyDueAssignments::class)->run();
        }

        $plan = $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $plan->getId());
        $this->assertSame(Status::DRAFT, $plan->get('status'));
        $this->assertNull($plan->get('appliedAt'));
        $survivor = $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $actual->getId());
        $this->assertNull($survivor->get('dateTo'));
        $this->assertNull($survivor->get('endedAt'));
        $this->assertSame([$a->getId()], $this->locations($user, 'today'));
    }

    public function testLateSuccessRecordsActualDayAndPreservesPlannedDateAndNextPlan(): void
    {
        [$user, $a, $g, $actual] = $this->fixtures();
        $em = $this->getEntityManager();
        $factory = $this->getInjectableFactory();
        $editor = $factory->create(AssignmentEditor::class);
        $plan = $editor->create($user->getId(), $g->getId(), Position::MEMBER, $this->day('-2 days'),
            null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $h = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'Scheduled next H']);
        $next = $editor->create($user->getId(), $h->getId(), Position::MEMBER, $this->day('+3 days'),
            null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $factory->create(ApplyDueAssignments::class)->run();
        $factory->create(ApplyDueAssignments::class)->run();

        $this->assertSame([$a->getId()], $this->locations($user, '-1 day'));
        $this->assertSame([$g->getId()], $this->locations($user, 'today'));
        $plan = $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $plan->getId());
        $this->assertSame($this->day('-2 days'), $plan->get('dateFrom'));
        $this->assertSame($this->day(), $plan->get('actualDateFrom'));
        $this->assertSame($this->day('+3 days'), $plan->get('dateTo'));
        $this->assertNull($plan->get('actualDateTo'));
        $actual = $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $actual->getId());
        $this->assertSame($this->day('-2 days'), $actual->get('dateTo'));
        $this->assertSame($this->day(), $actual->get('actualDateTo'));
        $next = $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $next->getId());
        $this->assertSame($plan->getId(), $next->get('supersedesId'));
        $this->assertSame(Status::CONFIRMED, $next->get('status'));
        $history = $factory->create(Service::class)->getMemberHistory($actual->get('boardMemberId'));
        $byId = array_column($history, null, 'id');
        $this->assertSame($this->day(), $byId[$plan->getId()]->dateFrom);
        $this->assertSame($this->day(), $byId[$actual->getId()]->dateTo);
        $pastWindow = $factory->create(Service::class)->getTimeline(
            $this->day('-1 day'), $this->day('-1 day'), $this->day(),
        );
        $personPeriods = array_values(array_filter($pastWindow->assignments,
            fn ($period) => $period->memberId === $user->getId()));
        $this->assertSame([$actual->getId()], array_column($personPeriods, 'id'));
    }

    public function testDelayedUntilRecordsRealReserveStartAndKeepsYesterdayInTeam(): void
    {
        [$user, $a, , $actual] = $this->fixtures();
        $em = $this->getEntityManager();
        $actual->set('dateTo', $this->day('-2 days'));
        $em->saveEntity($actual);
        $this->assertSame([$a->getId()], $this->locations($user, '-1 day'));
        $this->getInjectableFactory()->create(ApplyDueAssignments::class)->run();
        $this->assertSame([$a->getId()], $this->locations($user, '-1 day'));
        $this->assertSame(['reserve'], $this->locations($user, 'today'));
        $actual = $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $actual->getId());
        $this->assertSame($this->day('-2 days'), $actual->get('dateTo'));
        $this->assertSame($this->day(), $actual->get('actualDateTo'));
    }
}
