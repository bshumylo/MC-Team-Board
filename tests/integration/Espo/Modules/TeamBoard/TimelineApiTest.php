<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Timeline\Interval;
use Espo\Modules\TeamBoard\Tools\Timeline\Service;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\ORM\EntityManager;
use stdClass;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use tests\integration\Core\BaseTestCase;

class TimelineApiTest extends BaseTestCase
{
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
        ]);
    }

    private function createService(): Service
    {
        return $this->getInjectableFactory()->create(Service::class);
    }

    public function testTimelineReturnsCompositionAtTheGivenDate(): void
    {
        $team = $this->createTeam('Timeline');
        $member = $this->createMember('timeline.one');

        $service = $this->createService();
        $service->createAssignment((object) [
            'memberId' => $member->getId(),
            'teamId' => $team->getId(),
            'position' => Position::MEMBER,
            'dateFrom' => '2026-10-01',
            'dateTo' => '2026-12-01',
            'status' => Status::CONFIRMED,
        ]);

        $before = $service->getTimeline('2026-09-01', '2026-06-01', '2027-03-01');
        $during = $service->getTimeline('2026-10-15', '2026-06-01', '2027-03-01');
        $after = $service->getTimeline('2026-12-01', '2026-06-01', '2027-03-01');

        $this->assertSame([], $this->membersOf($before, $team->getId()));
        $this->assertSame([$member->getId()], $this->membersOf($during, $team->getId()));
        $this->assertSame([], $this->membersOf($after, $team->getId()));
    }

    public function testDraftOnlyAssignmentDoesNotCountAsActiveMembership(): void
    {
        $team = $this->createTeam('DraftOnly');
        $member = $this->createMember('draft.only');

        $this->getEntityManager()->createEntity('TeamBoardAssignment', [
            'memberId' => $member->getId(),
            'teamId' => $team->getId(),
            'position' => Position::MEMBER,
            'dateFrom' => '2099-01-01',
            'dateTo' => null,
            'status' => Status::DRAFT,
        ]);

        $payload = $this->createService()->getTimeline('2099-02-01', '2098-07-01', '2099-08-01');

        $this->assertSame([$member->getId()], $this->membersOf($payload, $team->getId()));
        // U06 (2026-09-25): shown once, at the Draft destination, not also in Reserve.
        $this->assertNotContains($member->getId(), array_map(
            fn (stdClass $entry): string => $entry->id,
            $payload->reserve
        ));
    }

    // U02 plans beyond a future selected date remain available separately.
    // U06 composition includes only periods effective on that date; a future
    // Draft must not move the person early or remove their factual Reserve.
    public function testDraftStartingAfterAFutureSelectedDateIsStillReturned(): void
    {
        $team = $this->createTeam('FutureDraftWindow');
        $member = $this->createMember('future.draft.window');

        $selectedDate = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $draftStart = (new \DateTimeImmutable('+60 days'))->format('Y-m-d');

        $this->getEntityManager()->createEntity('TeamBoardAssignment', [
            'memberId' => $member->getId(),
            'teamId' => $team->getId(),
            'position' => Position::MEMBER,
            'dateFrom' => $draftStart,
            'dateTo' => null,
            'status' => Status::DRAFT,
        ]);

        $payload = $this->createService()->getTimeline(
            $selectedDate,
            (new \DateTimeImmutable('+1 day'))->format('Y-m-d'),
            (new \DateTimeImmutable('+90 days'))->format('Y-m-d')
        );

        $pending = array_values(array_filter($payload->pendingDrafts,
            fn (stdClass $plan): bool => $plan->id === $member->getId()));
        $this->assertCount(1, $pending, 'Draft beyond the viewed date remains available for U02.');
        $this->assertSame($draftStart, $pending[0]->dateFrom);
        $this->assertGreaterThan($selectedDate, $pending[0]->dateFrom);
        $this->assertSame([], $this->membersOf($payload, $team->getId()), 'Future Draft has not started.');
        $this->assertContains($member->getId(), array_column($payload->reserve, 'id'));
    }

    public function testSeveralConfirmedPlansOnOneDayShowTheFinalPlacementWithoutWarnings(): void
    {
        $echo = $this->createTeam('Echo');
        $delta = $this->createTeam('Delta');
        $member = $this->createMember('overlap.one');

        foreach ([$echo, $delta] as $team) {
            $this->getEntityManager()->createEntity('TeamBoardAssignment', [
                'memberId' => $member->getId(),
                'teamId' => $team->getId(),
                'position' => Position::MEMBER,
                'dateFrom' => '2027-01-01',
                'dateTo' => null,
                'status' => Status::CONFIRMED,
            ]);
        }

        $payload = $this->createService()->getTimeline('2027-02-01', '2026-10-01', '2027-06-01');
        $placements = 0;

        foreach ($payload->teams as $team) {
            foreach ($team->members as $entry) {
                if ($entry->id === $member->getId()) {
                    $placements++;
                    $this->assertSame([], $entry->warnings);
                }
            }
        }

        $this->assertSame(1, $placements);
        $this->assertSame([$member->getId()], $this->membersOf($payload, $delta->getId()));
    }

    public function testZeroLengthPeriodsAreRejected(): void
    {
        $team = $this->createTeam('ZeroLength');
        $member = $this->createMember('zero.one');

        $this->expectException(\Espo\Core\Exceptions\BadRequest::class);
        $this->createService()->createAssignment((object) [
            'memberId' => $member->getId(), 'teamId' => $team->getId(),
            'position' => Position::MEMBER, 'dateFrom' => '2026-10-01',
            'dateTo' => '2026-10-01', 'status' => Status::DRAFT,
        ]);
    }

    public function testMissingTeamInputIsReportedAsNotFound(): void
    {
        $member = $this->createMember('missing.team.input');

        $this->expectException(NotFound::class);
        $this->createService()->createAssignment((object) [
            'memberId' => $member->getId(),
            'teamId' => 'does-not-exist',
            'position' => Position::MEMBER,
            'dateFrom' => '2026-12-01',
            'dateTo' => null,
            'status' => Status::DRAFT,
        ]);
    }

    public function testAUserWithViewButNoTeamEditCanReadAndChangeNothing(): void
    {
        $team = $this->createTeam('ReadOnly');
        $member = $this->createMember('readonly.target');
        $viewer = $this->createUser('readonly.viewer', ['data' => [
            'TeamBoard' => ['read' => 'all', 'edit' => 'no'],
            'Team' => ['read' => 'all', 'edit' => 'no'],
            'User' => ['read' => 'all', 'edit' => 'no'],
        ]]);

        $this->authenticate($viewer->getUserName());
        $service = $this->createService();

        $this->assertFalse($service->getTimeline('2026-09-01', '2026-06-01', '2027-03-01')->canManage);
        $this->expectException(Forbidden::class);
        $service->createAssignment((object) [
            'memberId' => $member->getId(), 'teamId' => $team->getId(),
            'position' => Position::MEMBER, 'dateFrom' => '2026-10-01',
            'dateTo' => null, 'status' => Status::DRAFT,
        ]);
    }

    public function testBooleanBoardViewerCannotManageWhenAssignmentEditIsDenied(): void
    {
        $viewer = $this->createUser('readonly.boolean.viewer', ['data' => [
            'TeamBoard' => true,
            'TeamBoardAssignment' => [
                'read' => 'all',
                'edit' => 'no',
                'create' => 'no',
                'delete' => 'no',
            ],
            'Team' => ['read' => 'all', 'edit' => 'no'],
            'User' => ['read' => 'all', 'edit' => 'no'],
        ]]);

        $this->authenticate($viewer->getUserName());

        $payload = $this->createService()->getTimeline(
            '2026-09-01',
            '2026-06-01',
            '2027-03-01'
        );

        $this->assertFalse($payload->canManage);
    }

    public function testALongRunningPeriodIsClassifiedAsLive(): void
    {
        $today = date('Y-m-d');
        $team = $this->createTeam('Live');
        $member = $this->createMember('live.one');
        $this->getEntityManager()->getRelation($team, 'users')->relate($member, ['role' => Position::MEMBER]);
        $member->set('defaultTeamId', $team->getId());
        $this->getEntityManager()->saveEntity($member);
        $fact = $this->getEntityManager()->getRDBRepository('TeamBoardAssignment')
            ->where(['memberId' => $member->getId(), 'isCrmState' => true, 'dateTo' => null])->findOne();
        $start = date('Y-m-d', strtotime($today . ' -30 days'));
        $fact->set(['dateFrom' => $start, 'actualDateFrom' => $start]);
        $this->getEntityManager()->saveEntity($fact);

        $payload = $this->createService()->getTimeline($today, $today, date('Y-m-d', strtotime($today . ' +1 day')));

        foreach ($payload->teams as $entry) {
            if ($entry->id === $team->getId()) {
                $this->assertSame(Interval::ZONE_LIVE, $entry->members[0]->zone);

                return;
            }
        }

        $this->fail('The created team was not returned.');
    }

    public function testEditorCannotRescheduleFuturePlanToTodayOrYesterday(): void
    {
        $team = $this->createTeam('Future boundary');
        $member = $this->createMember('future.boundary.target');
        $editor = $this->createUser('future.boundary.editor', ['data' => [
            'TeamBoard' => true,
            'TeamBoardAssignment' => ['read' => 'all', 'edit' => 'all', 'create' => 'all', 'delete' => 'all'],
            'Team' => ['read' => 'all', 'edit' => 'no'],
            'User' => ['read' => 'all', 'edit' => 'no'],
        ]]);
        $today = $this->getContainer()->getByClass(\Espo\Core\Utils\DateTime::class)->getToday()->toString();
        $future = (new \DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');
        $this->authenticate($editor->getUserName());
        $service = $this->createService();
        $service->createAssignment((object) [
            'memberId' => $member->getId(), 'teamId' => $team->getId(),
            'position' => Position::MEMBER, 'dateFrom' => $future, 'status' => Status::DRAFT,
        ]);
        $repository = $this->getEntityManager()->getRDBRepository('TeamBoardAssignment');
        $plan = $repository->where(['memberId' => $member->getId(), 'status' => Status::DRAFT])->findOne();
        $this->assertNotNull($plan);
        foreach ([$today, (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d')] as $start) {
            try {
                $service->updateAssignment($plan->getId(), (object) ['dateFrom' => $start, 'status' => Status::CONFIRMED]);
                $this->fail('Editor rescheduled a future plan into today or the past.');
            } catch (Forbidden $exception) {
                $this->assertSame('Not allowed to change this period.', $exception->getMessage());
            }
            $fresh = $repository->where(['id' => $plan->getId()])->findOne();
            $this->assertSame($future, $fresh->get('dateFrom'));
            $this->assertSame(Status::DRAFT, $fresh->get('status'));
        }
        $nextFuture = (new \DateTimeImmutable($future))->modify('+1 day')->format('Y-m-d');
        $service->updateAssignment($plan->getId(), (object) ['dateFrom' => $nextFuture]);
        $this->assertSame($nextFuture, $repository->where(['id' => $plan->getId()])->findOne()->get('dateFrom'));
    }

    public function testAdminCurrentTeamChangePreservesYesterdayAndAppliesANewTransition(): void
    {
        $em = $this->getEntityManager();
        $em->createEntity(User::ENTITY_TYPE, [
            'userName' => 'actual.history.admin', 'lastName' => 'Job admin',
            'type' => User::TYPE_ADMIN, 'isActive' => true,
        ]);
        $teamA = $this->createTeam('Actual A');
        $teamB = $this->createTeam('Actual B');
        $member = $this->createMember('actual.admin.target');
        $em->getRelation($teamA, 'users')->relate($member, ['role' => Position::MEMBER]);
        $member->set('defaultTeamId', $teamA->getId());
        $em->saveEntity($member);
        $today = $this->getContainer()->getByClass(\Espo\Core\Utils\DateTime::class)->getToday()->toString();
        $yesterday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $repository = $em->getRDBRepository('TeamBoardAssignment');
        $fact = $repository->where(['memberId' => $member->getId(), 'isCrmState' => true, 'dateTo' => null])->findOne();
        $fact->set(['dateFrom' => $yesterday, 'actualDateFrom' => $yesterday]);
        $em->saveEntity($fact);
        $service = $this->createService();
        $service->updateAssignment($fact->getId(), (object) [
            'teamId' => $teamB->getId(), 'dateFrom' => $yesterday, 'dateTo' => null, 'status' => Status::CONFIRMED,
        ]);
        $beforeJob = $service->getTimeline($yesterday, $yesterday, $today);
        $this->assertSame([$member->getId()], $this->membersOf($beforeJob, $teamA->getId()));
        $this->assertSame([], $this->membersOf($beforeJob, $teamB->getId()));
        $service->updateAssignment($fact->getId(), (object) [
            'teamId' => $teamB->getId(), 'dateFrom' => $yesterday, 'dateTo' => null, 'status' => Status::CONFIRMED,
        ]);
        $this->assertSame(1, $repository->where(['memberId' => $member->getId(), 'teamId' => $teamB->getId()])->count());
        $this->getInjectableFactory()->create(\Espo\Modules\TeamBoard\Jobs\ApplyDueAssignments::class)->run();
        $afterJob = $service->getTimeline($today, $yesterday, (new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d'));
        $this->assertSame([$member->getId()], $this->membersOf($afterJob, $teamB->getId()));
        $this->assertSame($teamB->getId(), $em->getRDBRepositoryByClass(User::class)->getById($member->getId())->get('defaultTeamId'));
        $past = $service->getTimeline($yesterday, $yesterday, $today);
        $this->assertSame([$member->getId()], $this->membersOf($past, $teamA->getId()));
    }

    public function testActualPeriodRejectsHistoricalChangesButAllowsFutureUntil(): void
    {
        $em = $this->getEntityManager();
        $team = $this->createTeam('Actual guard');
        $member = $this->createMember('actual.guard.target');
        $em->getRelation($team, 'users')->relate($member, ['role' => Position::MEMBER]);
        $member->set('defaultTeamId', $team->getId());
        $em->saveEntity($member);
        $today = $this->getContainer()->getByClass(\Espo\Core\Utils\DateTime::class)->getToday()->toString();
        $start = (new \DateTimeImmutable($today))->modify('-10 days')->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $future = (new \DateTimeImmutable($today))->modify('+10 days')->format('Y-m-d');
        $repo = $em->getRDBRepository('TeamBoardAssignment');
        $fact = $repo->where(['memberId' => $member->getId(), 'isCrmState' => true, 'dateTo' => null])->findOne();
        $fact->set(['dateFrom' => $start, 'actualDateFrom' => $start]);
        $em->saveEntity($fact);
        $service = $this->createService();
        foreach ([['status' => Status::DRAFT], ['dateFrom' => $yesterday], ['dateTo' => $yesterday]] as $input) {
            try {
                $service->updateAssignment($fact->getId(), (object) $input);
                $this->fail('A factual period was rewritten.');
            } catch (Forbidden) {
                $stored = $repo->where(['id' => $fact->getId()])->findOne();
                $this->assertSame($start, $stored->get('dateFrom'));
                $this->assertSame(Status::CONFIRMED, $stored->get('status'));
                $this->assertNull($stored->get('dateTo'));
            }
        }
        $service->updateAssignment($fact->getId(), (object) ['dateTo' => $future]);
        $service->updateAssignment($fact->getId(), (object) ['dateTo' => $future]);
        $stored = $repo->where(['id' => $fact->getId()])->findOne();
        $this->assertSame($future, $stored->get('dateTo'));
        $this->assertSame($start, $stored->get('actualDateFrom'));
        $this->assertSame(1, $repo->where(['memberId' => $member->getId()])->count());
        $this->assertSame($team->getId(), $em->getRDBRepositoryByClass(User::class)->getById($member->getId())->get('defaultTeamId'));
    }

    /** @return string[] */
    public function testDraggingAJustOpenedLiveMemberToReserveClosesItInsteadOfRejecting(): void
    {
        // Reproduces the D8 A2-reserve-drag report: admin drags a
        // CRM-linked member out of a team the same day their live
        // membership shadow assignment started. Closing "today" then asks
        // for dateTo === dateFrom, a zero-length window that must remove
        // the membership (T16/T20) instead of a 400 "End date must be
        // after the start date".
        $em = $this->getEntityManager();
        $em->createEntity(User::ENTITY_TYPE, [
            'userName' => 'reserve.drag.admin', 'lastName' => 'Reserve drag admin',
            'type' => User::TYPE_ADMIN, 'isActive' => true,
        ]);
        $team = $this->createTeam('Reserve drag team');
        $member = $this->createMember('reserve.drag.target');
        $em->getRelation($team, 'users')->relate($member, ['role' => Position::MEMBER]);
        $member->set('defaultTeamId', $team->getId());
        $em->saveEntity($member);

        $today = $this->getContainer()->getByClass(\Espo\Core\Utils\DateTime::class)->getToday()->toString();
        $repository = $em->getRDBRepository('TeamBoardAssignment');
        $fact = $repository->where(['memberId' => $member->getId(), 'isCrmState' => true, 'dateTo' => null])
            ->findOne();
        $fact->set(['dateFrom' => $today, 'actualDateFrom' => $today]);
        $em->saveEntity($fact);

        $service = $this->createService();
        $payload = $service->updateAssignment($fact->getId(), (object) [
            'dateTo' => $today,
            'viewDate' => $today,
        ]);

        $this->assertSame([], $this->membersOf($payload, $team->getId()));
        $this->assertNull($repository->where(['id' => $fact->getId()])->findOne());

        // The real CRM state is what actually decides today's placement
        // (CrmPlacement::forUser reads team_user + defaultTeamId, not this
        // row's own dateTo): a persisted PUT with no relation change would
        // leave the member showing back on the team on the next refresh.
        $this->assertFalse($em->getRelation($team, 'users')->isRelated($member));
        $refreshedMember = $em->getRDBRepositoryByClass(User::class)->getById($member->getId());
        $this->assertNull($refreshedMember->get('defaultTeamId'));
        $reserveIds = array_map(fn (stdClass $entry) => $entry->id, $payload->reserve);
        $this->assertContains($member->getId(), $reserveIds);
    }

    public function testDraggingAnOlderLiveMemberToReserveEndsCrmMembershipImmediately(): void
    {
        // D8 A2-reserve-drag round 3: the live CRM-state fact started before
        // today. Closing it "today" stored dateTo but left the real CRM
        // team_user relation for the ApplyDueAssignments job, so the member
        // stayed on the team after the drag (U21/T16/T20).
        $em = $this->getEntityManager();
        $team = $this->createTeam('Reserve drag older team');
        $member = $this->createMember('reserve.drag.older');
        $em->getRelation($team, 'users')->relate($member, ['role' => Position::MEMBER]);
        $member->set('defaultTeamId', $team->getId());
        $em->saveEntity($member);

        $today = $this->getContainer()->getByClass(\Espo\Core\Utils\DateTime::class)->getToday()->toString();
        $yesterday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $repository = $em->getRDBRepository('TeamBoardAssignment');
        $fact = $repository->where(['memberId' => $member->getId(), 'isCrmState' => true, 'dateTo' => null])
            ->findOne();
        $fact->set(['dateFrom' => $yesterday, 'actualDateFrom' => $yesterday]);
        $em->saveEntity($fact);

        $payload = $this->createService()->updateAssignment($fact->getId(), (object) [
            'dateTo' => $today,
            'viewDate' => $today,
        ]);

        $this->assertSame([], $this->membersOf($payload, $team->getId()));
        $this->assertFalse($em->getRelation($team, 'users')->isRelated($member));
        $this->assertNull(
            $em->getRDBRepositoryByClass(User::class)->getById($member->getId())->get('defaultTeamId')
        );
        $reserveIds = array_map(fn (stdClass $entry) => $entry->id, $payload->reserve);
        $this->assertContains($member->getId(), $reserveIds);

        // The earlier fact is kept as history, ended today.
        $kept = $repository->where(['id' => $fact->getId()])->findOne();
        $this->assertNotNull($kept);
        $this->assertSame($today, $kept->get('dateTo'));
        $this->assertNotNull($kept->get('endedAt'));
    }

    public function testDraggingALiveMemberWithARetiredPositionToReserveIsNotRejected(): void
    {
        // D8 A2-reserve-drag (review №2, cycle 2 round 1): a CRM member whose
        // stored position is no longer in the team's position list could not
        // be dragged to Reserve — the unchanged position was re-validated and
        // the PUT failed with 400 "Not allowed position." (U21/T16/T20).
        $em = $this->getEntityManager();
        $team = $this->createTeam('Reserve drag retired position');
        $member = $this->createMember('reserve.drag.retired');
        $em->getRelation($team, 'users')->relate($member, ['role' => Position::MEMBER]);
        $member->set('defaultTeamId', $team->getId());
        $em->saveEntity($member);

        $today = $this->getContainer()->getByClass(\Espo\Core\Utils\DateTime::class)->getToday()->toString();
        $yesterday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $repository = $em->getRDBRepository('TeamBoardAssignment');
        $fact = $repository->where(['memberId' => $member->getId(), 'isCrmState' => true, 'dateTo' => null])
            ->findOne();
        $fact->set(['dateFrom' => $yesterday, 'actualDateFrom' => $yesterday, 'position' => 'Retired Role']);
        $em->saveEntity($fact);

        $payload = $this->createService()->updateAssignment($fact->getId(), (object) [
            'dateTo' => $today,
            'viewDate' => $today,
        ]);

        $this->assertSame([], $this->membersOf($payload, $team->getId()));
        $this->assertFalse($em->getRelation($team, 'users')->isRelated($member));
        $reserveIds = array_map(fn (stdClass $entry) => $entry->id, $payload->reserve);
        $this->assertContains($member->getId(), $reserveIds);
        $this->assertSame('Retired Role', $repository->where(['id' => $fact->getId()])->findOne()->get('position'));
    }

    public function testDraggingAPlannedMemberWithARetiredPositionToReserveEndsThePlan(): void
    {
        $em = $this->getEntityManager();
        $team = $this->createTeam('Reserve drag retired plan');
        $member = $this->createMember('reserve.drag.retired.plan');
        $today = $this->getContainer()->getByClass(\Espo\Core\Utils\DateTime::class)->getToday()->toString();
        $start = (new \DateTimeImmutable($today))->modify('+20 days')->format('Y-m-d');
        $view = (new \DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');

        $service = $this->createService();
        $service->createAssignment((object) [
            'memberId' => $member->getId(), 'teamId' => $team->getId(),
            'position' => Position::MEMBER, 'dateFrom' => $start, 'status' => Status::DRAFT,
        ]);
        $repository = $em->getRDBRepository('TeamBoardAssignment');
        $plan = $repository->where(['memberId' => $member->getId(), 'status' => Status::DRAFT])->findOne();
        $plan->set('position', 'Retired Role');
        $em->saveEntity($plan);

        $payload = $service->updateAssignment($plan->getId(), (object) ['dateTo' => $view, 'viewDate' => $view]);

        $this->assertSame([], $this->membersOf($payload, $team->getId()));
        $this->assertSame($view, $repository->where(['id' => $plan->getId()])->findOne()->get('dateTo'));
    }

    public function testChangingToARetiredPositionIsStillRejected(): void
    {
        $team = $this->createTeam('Retired position change');
        $member = $this->createMember('retired.position.change');
        $today = $this->getContainer()->getByClass(\Espo\Core\Utils\DateTime::class)->getToday()->toString();
        $start = (new \DateTimeImmutable($today))->modify('+20 days')->format('Y-m-d');
        $service = $this->createService();
        $service->createAssignment((object) [
            'memberId' => $member->getId(), 'teamId' => $team->getId(),
            'position' => Position::MEMBER, 'dateFrom' => $start, 'status' => Status::DRAFT,
        ]);
        $plan = $this->getEntityManager()->getRDBRepository('TeamBoardAssignment')
            ->where(['memberId' => $member->getId(), 'status' => Status::DRAFT])->findOne();

        $this->expectException(\Espo\Core\Exceptions\BadRequest::class);
        $service->updateAssignment($plan->getId(), (object) ['position' => 'Retired Role']);
    }

    private function membersOf(stdClass $payload, string $teamId): array
    {
        foreach ($payload->teams as $team) {
            if ($team->id === $teamId) {
                return array_map(fn (stdClass $entry) => $entry->id, $team->members);
            }
        }

        return [];
    }

    public function testHistoryShowsOnlyTheLastStateOfADayNotZeroLengthPeriods(): void
    {
        $team = $this->createTeam('History same day');
        $member = $this->createMember('history.same.day');
        $editor = $this->getInjectableFactory()->create(AssignmentEditor::class);
        $day = (new \DateTimeImmutable('+5 days'))->format('Y-m-d');

        $editor->create($member->getId(), $team->getId(), Position::MEMBER, $day, null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $editor->create($member->getId(), $team->getId(), Position::LEADER, $day, null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $boardMemberId = $this->getInjectableFactory()->create(Registry::class)->memberForUser($member->getId())->getId();
        $rows = array_values(array_filter(
            $this->createService()->getMemberHistory($boardMemberId),
            static fn (stdClass $row) => $row->teamId === $team->getId()
        ));

        $this->assertCount(1, $rows);
        $this->assertSame(Position::LEADER, $rows[0]->position);
    }
}
