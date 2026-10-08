<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\Modules\TeamBoard\Tools\Timeline\Service;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\ORM\Entity;
use stdClass;
use tests\integration\Core\BaseTestCase;

class CrmPlacementTest extends BaseTestCase
{
    private function day(string $offset = 'today'): string
    {
        return (new \DateTimeImmutable($offset))->format('Y-m-d');
    }

    private function person(): User
    {
        return $this->getEntityManager()->createEntity(User::ENTITY_TYPE, [
            'userName' => 'placement.target', 'lastName' => 'Placement target',
            'type' => User::TYPE_REGULAR, 'isActive' => true,
        ]);
    }

    private function team(string $name, User $user): Team
    {
        $team = $this->getEntityManager()->createEntity(Team::ENTITY_TYPE, ['name' => $name]);
        $this->getEntityManager()->getRelation($team, 'users')->relate($user, ['role' => Position::MEMBER]);
        return $team;
    }

    private function setDefault(User $user, ?Team $team): void
    {
        $user->set('defaultTeamId', $team?->getId());
        $this->getEntityManager()->saveEntity($user);
    }

    private function board(string $day = 'today'): stdClass
    {
        return $this->getInjectableFactory()->create(Service::class)
            ->getTimeline($this->day($day), $this->day('-1 year'), $this->day('+1 year'));
    }

    private function reloadUserDefault(User $user): ?string
    {
        return $this->getEntityManager()->getRDBRepositoryByClass(User::class)
            ->getById($user->getId())?->get('defaultTeamId');
    }

    private function placements(stdClass $board, User $user): array
    {
        $ids = [];
        foreach ($board->teams as $team) {
            foreach ($team->members as $member) {
                if ($member->userId === $user->getId() && $member->status === Status::CONFIRMED) {
                    $ids[] = $team->teamId;
                }
            }
        }
        return $ids;
    }

    private function inReserve(stdClass $board, User $user): bool
    {
        return in_array($user->getId(), array_column($board->reserve, 'userId'), true);
    }

    private function currentFact(User $user): Entity
    {
        return $this->getEntityManager()->getRDBRepository(AssignmentQuery::ENTITY_TYPE)
            ->where(['memberId' => $user->getId(), 'isCrmState' => true, 'dateTo' => null])->findOne();
    }

    public function testCurrentDefaultWinsOverUnappliedDuePlanAndOtherMemberships(): void
    {
        $user = $this->person();
        $a = $this->team('Actual A', $user);
        $b = $this->team('Other B', $user);
        $this->setDefault($user, $a);
        $this->getEntityManager()->createEntity(AssignmentQuery::ENTITY_TYPE, [
            'memberId' => $user->getId(), 'teamId' => $b->getId(),
            'dateFrom' => $this->day(), 'position' => Position::MEMBER, 'status' => Status::CONFIRMED,
        ]);
        $this->assertSame([$a->getId()], $this->placements($this->board(), $user));
        $this->assertFalse($this->inReserve($this->board(), $user));
    }

    /**
     * A team_user relation with no recorded shadow row yet (e.g. created
     * before the module ever reconciled it) is shown as a virtual CRM
     * snapshot. Its `assignmentId` must read back null, not the
     * non-persisted `snapshot-<userId>` id `CrmPlacement` builds for
     * display — a null-only check lets the board fall back to unrelating
     * the CRM membership directly on drag-to-reserve (A2-reserve-drag);
     * a truthy synthetic id instead sends a PUT to an id that was never
     * saved, which 404s and looks like the drag did nothing.
     */
    public function testVirtualSnapshotAssignmentIdIsNullNotASyntheticId(): void
    {
        $user = $this->person();
        $team = $this->team('Live Only', $user);
        $this->setDefault($user, $team);

        $fact = $this->currentFact($user);
        $this->assertNotNull($fact, 'relating to a team records a shadow row');
        $this->getEntityManager()->removeEntity($fact);

        $member = null;

        foreach ($this->board()->teams as $boardTeam) {
            foreach ($boardTeam->members as $candidate) {
                if ($candidate->userId === $user->getId()) {
                    $member = $candidate;
                }
            }
        }

        $this->assertNotNull($member, 'the member is still shown, from the live CRM relation');
        $this->assertNull($member->assignmentId, 'a virtual snapshot must not expose a synthetic assignment id');
    }

    public function testSnapshotReadsCrmEvenIfAnOldFactualRecordDisagrees(): void
    {
        $user = $this->person();
        $a = $this->team('Old A', $user);
        $b = $this->team('Current B', $user);
        $this->setDefault($user, $a);
        // Simulate a stale baseline from an older installation, without
        // allowing the read request to rewrite historical records.
        $user->set('defaultTeamId', $b->getId());
        $this->getEntityManager()->saveEntity($user, [SaveOption::SKIP_HOOKS => true]);
        $fact = $this->currentFact($user);
        $this->assertSame([$b->getId()], $this->placements($this->board(), $user));
        $this->assertSame($a->getId(), $this->getEntityManager()
            ->getEntityById(AssignmentQuery::ENTITY_TYPE, $fact->getId())->get('teamId'));
    }

    public function testMissingDefaultAndDanglingDefaultAreReserveWithoutAutomaticChoice(): void
    {
        $user = $this->person();
        $a = $this->team('Membership A', $user);
        $this->assertTrue($this->inReserve($this->board(), $user));
        $this->assertSame([], $this->placements($this->board(), $user));
        $this->setDefault($user, $a);
        $this->getEntityManager()->getRelation($a, 'users')->unrelate($user);
        $this->assertTrue($this->inReserve($this->board(), $user));
        $this->assertSame([], $this->placements($this->board(), $user));
    }

    public function testPastReserveSurvivesDeactivationAndNewPeopleDoNotAppearInThePast(): void
    {
        $user = $this->person();
        $this->assertFalse($this->inReserve($this->board('-2 days'), $user));
        $fact = $this->currentFact($user);
        $fact->set(['dateFrom' => $this->day('-1 day'), 'actualDateFrom' => $this->day('-1 day')]); // Recorded previous day.
        $this->getEntityManager()->saveEntity($fact);
        $user->set('isActive', false);
        $this->getEntityManager()->saveEntity($user);
        $this->assertTrue($this->inReserve($this->board('-1 day'), $user));
        $this->assertFalse($this->inReserve($this->board(), $user));
        $this->assertFalse($this->inReserve($this->board('+1 day'), $user));
    }

    public function testInactiveUserKeepsPastTeamButDoesNotReappearThroughFutureDraft(): void
    {
        $user = $this->person();
        $a = $this->team('History A', $user);
        $b = $this->team('Future B', $user);
        $this->setDefault($user, $a);
        $fact = $this->currentFact($user);
        $fact->set(['dateFrom' => $this->day('-1 day'), 'actualDateFrom' => $this->day('-1 day')]);
        $this->getEntityManager()->saveEntity($fact);
        $this->getInjectableFactory()->create(AssignmentEditor::class)->create(
            $user->getId(), $b->getId(), Position::MEMBER, $this->day('+1 day'), null,
            Status::CONFIRMED, null, Position::DEFAULT_LIST
        );
        $user->set('isActive', false);
        $this->getEntityManager()->saveEntity($user);
        $this->assertSame([$a->getId()], $this->placements($this->board('-1 day'), $user));
        foreach ([$this->board(), $this->board('+1 day')] as $board) {
            foreach ($board->teams as $team) {
                $this->assertNotContains($user->getId(), array_column($team->members, 'userId'));
            }
            $this->assertFalse($this->inReserve($board, $user));
        }
    }

    public function testFutureUntilLeadsToReserveAndDraftDoesNotDisplaceActualTeam(): void
    {
        $user = $this->person();
        $a = $this->team('Base A', $user);
        $b = $this->team('Plan B', $user);
        $this->setDefault($user, $a);
        $editor = $this->getInjectableFactory()->create(AssignmentEditor::class);
        $draft = $editor->create($user->getId(), $b->getId(), Position::MEMBER,
            $this->day('+1 day'), $this->day('+2 days'), Status::DRAFT, null, Position::DEFAULT_LIST);
        // U06 (2026-09-25): the Draft in effect on the viewed date shows the
        // person once, at the destination; the actual team is not repeated.
        $this->assertSame([], $this->placements($this->board('+1 day'), $user));
        $this->assertSame([$a->getId()], $this->placements($this->board(), $user));
        $this->assertFalse($this->inReserve($this->board('+1 day'), $user));
        $this->assertSame($a->getId(), $this->reloadUserDefault($user), 'T05: Draft leaves CRM default');
        $editor->update($draft, Position::MEMBER, $b->getId(), $this->day('+1 day'),
            $this->day('+2 days'), Status::CONFIRMED, null);
        $this->assertSame([$a->getId()], $this->placements($this->board(), $user));
        $this->assertSame([$b->getId()], $this->placements($this->board('+1 day'), $user));
        $this->assertSame([], $this->placements($this->board('+2 days'), $user));
        $this->assertTrue($this->inReserve($this->board('+2 days'), $user));
    }

    public function testHiddenCurrentTeamDoesNotPutItsMemberIntoReserve(): void
    {
        $user = $this->person();
        $a = $this->team('Hidden current A', $user);
        $this->setDefault($user, $a);
        $viewer = $this->createUser('placement.viewer', ['data' => [
            'TeamBoard' => true, 'Team' => ['read' => 'no'], 'User' => ['read' => 'all'],
        ]]);
        $this->authenticate($viewer->getUserName());
        $this->assertTrue($this->getContainer()->getByClass(Acl::class)->checkEntityRead($user));
        $board = $this->board();
        $this->assertSame([], $this->placements($board, $user));
        $this->assertFalse($this->inReserve($board, $user));
    }

    public function testUserReadAccessAlsoProtectsRangeAssignmentsAndDirectHistory(): void
    {
        $user = $this->person();
        $a = $this->team('Visible team', $user);
        $this->setDefault($user, $a);
        $member = $this->getInjectableFactory()->create(Registry::class)->memberForUser($user->getId());
        $viewer = $this->createUser('placement.own.viewer', ['data' => [
            'TeamBoard' => true, 'Team' => ['read' => 'all'], 'User' => ['read' => 'own'],
        ]]);
        $this->authenticate($viewer->getUserName());
        $this->assertFalse($this->getContainer()->getByClass(Acl::class)->checkEntityRead($user));
        $board = $this->board();
        $this->assertNotContains($user->getId(), array_column($board->assignments, 'memberId'));
        $this->assertSame([], $this->placements($board, $user));
        $this->assertFalse($this->inReserve($board, $user));
        $this->expectException(Forbidden::class);
        $this->getInjectableFactory()->create(Service::class)->getMemberHistory($member->getId());
    }

    public function testHistoryIncludesRecordedReserveAndInactiveIntervals(): void
    {
        $user = $this->person();
        $fact = $this->currentFact($user);
        $fact->set(['dateFrom' => $this->day('-1 day'), 'actualDateFrom' => $this->day('-1 day')]);
        $this->getEntityManager()->saveEntity($fact);
        $user->set('isActive', false);
        $this->getEntityManager()->saveEntity($user);
        $member = $this->getInjectableFactory()->create(Registry::class)->memberForUser($user->getId());
        $history = $this->getInjectableFactory()->create(Service::class)->getMemberHistory($member->getId());
        $this->assertSame(['inactive', 'reserve'], array_column($history, 'state'));
        $this->assertSame([$this->day(), $this->day('-1 day')], array_column($history, 'dateFrom'));
        $this->assertSame([null, null], array_column($history, 'teamId'));
    }

    public function testHistoryCollapsesIdenticalSameDayDuplicates(): void
    {
        // T12: two assignment rows for the same person/team/day with the
        // same status and position must show as one History record. Such
        // duplicate rows can exist in the data (e.g. re-recorded facts)
        // even though they were never created as duplicates through this
        // same call.
        $user = $this->person();
        $team = $this->team('History dupes', $user);
        $member = $this->getInjectableFactory()->create(Registry::class)->memberForUser($user->getId());

        $row = [
            'boardMemberId' => $member->getId(),
            'teamId' => $team->getId(),
            'position' => Position::MEMBER,
            'dateFrom' => $this->day('-1 day'),
            'dateTo' => $this->day(),
            'status' => Status::CONFIRMED,
        ];
        $this->getEntityManager()->createEntity(AssignmentQuery::ENTITY_TYPE, $row);
        $this->getEntityManager()->createEntity(AssignmentQuery::ENTITY_TYPE, $row);

        $history = $this->getInjectableFactory()->create(Service::class)->getMemberHistory($member->getId());
        $matching = array_values(array_filter($history, fn (stdClass $h) => $h->teamId === $team->getId()));

        $this->assertCount(1, $matching, 'identical same-day/team/status/position rows must collapse to one');
    }

    public function testDeactivationDoesNotLeaveAReopenedAppliedPeriodOpen(): void
    {
        $user = $this->person();
        $a = $this->team('Reopen A', $user);
        $b = $this->team('Reopen B', $user);
        $this->setDefault($user, $a);
        $fact = $this->currentFact($user);
        $fact->set(['dateFrom' => $this->day('-1 day'), 'actualDateFrom' => $this->day('-1 day')]);
        $this->getEntityManager()->saveEntity($fact);
        $plan = $this->getInjectableFactory()->create(AssignmentEditor::class)->create(
            $user->getId(), $b->getId(), Position::MEMBER, $this->day('+5 days'), null,
            Status::CONFIRMED, null, Position::DEFAULT_LIST
        );
        $this->assertSame($this->day('+5 days'), $this->getEntityManager()
            ->getEntityById(AssignmentQuery::ENTITY_TYPE, $fact->getId())?->get('dateTo'));

        $user->set('isActive', false);
        $this->getEntityManager()->saveEntity($user);

        // returnToDraft() reopens the applied predecessor; the recorder must
        // then close it at the deactivation date rather than leave it open.
        $reloaded = $this->getEntityManager()->getEntityById(AssignmentQuery::ENTITY_TYPE, $fact->getId());
        $this->assertNotNull($reloaded);
        $this->assertSame($this->day(), $reloaded->get('dateTo'));
        $this->assertSame($this->day('-1 day'), $reloaded->get('dateFrom'));
        $this->assertSame(Status::DRAFT, $this->getEntityManager()
            ->getEntityById(AssignmentQuery::ENTITY_TYPE, $plan->getId())?->get('status'));
        foreach ($this->getEntityManager()->getRDBRepository(AssignmentQuery::ENTITY_TYPE)
            ->where(['memberId' => $user->getId(), 'status' => Status::CONFIRMED, 'teamId' => $a->getId()])
            ->find() as $row) {
            $this->assertNotNull($row->get('dateTo'));
            $this->assertLessThanOrEqual($this->day(), $row->get('dateTo'));
        }
    }
}
