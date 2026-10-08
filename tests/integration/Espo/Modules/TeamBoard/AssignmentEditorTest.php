<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Core\Exceptions\BadRequest;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\Modules\TeamBoard\Tools\Timeline\CrmChangeHandler;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;
use tests\integration\Core\BaseTestCase;

class AssignmentEditorTest extends BaseTestCase
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
        ]);
    }

    private function createEditor(): AssignmentEditor
    {
        return $this->getInjectableFactory()->create(AssignmentEditor::class);
    }

    private function reload(Entity $entity): Entity
    {
        $found = $this->getEntityManager()->getEntityById(self::ASSIGNMENT, $entity->getId());

        $this->assertNotNull($found);

        return $found;
    }

    public function testCreatingAMoveClosesThePreviousPeriodAndRecordsWhatItClosed(): void
    {
        $alpha = $this->createTeam('Alpha');
        $bravo = $this->createTeam('Bravo');
        $member = $this->createMember('mover.one');
        $editor = $this->createEditor();

        $inAlpha = $editor->create($member->getId(), $alpha->getId(), Position::MEMBER, '2026-01-01', null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $inBravo = $editor->create($member->getId(), $bravo->getId(), Position::MEMBER, $this->day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $this->assertSame($this->day(10), $this->reload($inAlpha)->get('dateTo'));
        $this->assertSame($inAlpha->getId(), $inBravo->get('supersedesId'));
        $this->assertNull($inBravo->get('supersededDateTo'));
    }

    public function testDraftConfirmationAndReturnToDraftPreservePredecessor(): void
    {
        $alpha = $this->createTeam('Draft isolation Alpha');
        $bravo = $this->createTeam('Draft isolation Bravo');
        $member = $this->createMember('draft.isolation');
        $editor = $this->createEditor();
        $previous = $editor->create($member->getId(), $alpha->getId(), Position::MEMBER,
            '2026-01-01', null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $draft = $editor->create($member->getId(), $bravo->getId(), Position::MEMBER,
            $this->day(70), null, Status::DRAFT, null, Position::DEFAULT_LIST);
        $this->assertNull($this->reload($previous)->get('dateTo'));
        $this->assertNull($this->reload($draft)->get('supersedesId'));
        $editor->update($draft, Position::MEMBER, $bravo->getId(), $this->day(70), null, Status::CONFIRMED, null);
        $this->assertSame($this->day(70), $this->reload($previous)->get('dateTo'));
        $this->assertSame($previous->getId(), $this->reload($draft)->get('supersedesId'));
        $editor->update($draft, Position::MEMBER, $bravo->getId(), $this->day(70), null, Status::DRAFT, null);
        $this->assertNull($this->reload($previous)->get('dateTo'));
        $this->assertNull($this->reload($draft)->get('supersedesId'));
        $editor->delete($draft);
        $this->assertNull($this->reload($previous)->get('dateTo'));
    }

    public function testTheSupersededEndDateIsRememberedWhenThereWasOne(): void
    {
        $alpha = $this->createTeam('Alpha2');
        $bravo = $this->createTeam('Bravo2');
        $member = $this->createMember('mover.two');
        $editor = $this->createEditor();

        $inAlpha = $editor->create($member->getId(), $alpha->getId(), Position::MEMBER, '2026-01-01', $this->day(100), Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $inBravo = $editor->create($member->getId(), $bravo->getId(), Position::MEMBER, $this->day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $this->assertSame($this->day(100), $inBravo->get('supersededDateTo'));
        $this->assertSame($this->day(10), $this->reload($inAlpha)->get('dateTo'));
    }

    public function testAMoveOutOfReserveClosesNothing(): void
    {
        $alpha = $this->createTeam('Alpha3');
        $member = $this->createMember('reserve.one');

        $created = $this->createEditor()->create($member->getId(), $alpha->getId(), Position::MEMBER, $this->day(10), null, Status::DRAFT, null, Position::DEFAULT_LIST);

        $this->assertNull($created->get('supersedesId'));
    }

    public function testPromotingIntoAnOccupiedLeaderSlotSplitsTheIncumbent(): void
    {
        $team = $this->createTeam('Split');
        $incumbent = $this->createMember('incumbent.one');
        $successor = $this->createMember('successor.one');
        $editor = $this->createEditor();

        $leaderPeriod = $editor->create($incumbent->getId(), $team->getId(), Position::LEADER, '2026-05-01', $this->day(200), Status::CONFIRMED, 'handover notes', Position::DEFAULT_LIST);
        $editor->create($successor->getId(), $team->getId(), Position::LEADER, $this->day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $closed = $this->reload($leaderPeriod);
        $this->assertSame(Position::LEADER, $closed->get('position'));
        $this->assertSame($this->day(10), $closed->get('dateTo'));

        $tail = $this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)->where([
            'memberId' => $incumbent->getId(),
            'dateFrom' => $this->day(10),
        ])->findOne();

        $this->assertNotNull($tail);
        $this->assertSame(Position::MEMBER, $tail->get('position'));
        $this->assertSame($this->day(200), $tail->get('dateTo'));
        $this->assertSame(Status::CONFIRMED, $tail->get('status'));
        $this->assertSame('handover notes', $tail->get('note'));
    }

    public function testASplitTailRecordsThePlanThatDisplacedIt(): void
    {
        $team = $this->createTeam('Split ownership');
        $incumbent = $this->createMember('owned.incumbent');
        $successor = $this->createMember('owned.successor');
        $editor = $this->createEditor();

        $editor->create($incumbent->getId(), $team->getId(), Position::LEADER, '2026-05-01', $this->day(200), Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $plan = $editor->create($successor->getId(), $team->getId(), Position::LEADER, $this->day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $tail = $this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)->where([
            'memberId' => $incumbent->getId(),
            'dateFrom' => $this->day(10),
        ])->findOne();

        $this->assertNotNull($tail);
        // The tail belongs to the promotion that produced it, not to the
        // person it demoted; their own CRM changes must not reset it.
        $this->assertSame($plan->getId(), $tail->get('displacedById'));
        $this->assertNull($this->reload($plan)->get('displacedById'));
    }

    public function testTakingOverAnOccupiedViceLeaderSlotSplitsTheIncumbentToo(): void
    {
        $team = $this->createTeam('Vice split');
        $incumbent = $this->createMember('vice.incumbent');
        $successor = $this->createMember('vice.successor');
        $editor = $this->createEditor();

        $vicePeriod = $editor->create($incumbent->getId(), $team->getId(), Position::VICE_LEADER, '2026-05-01', $this->day(200), Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $plan = $editor->create($successor->getId(), $team->getId(), Position::VICE_LEADER, $this->day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        // Two people may not hold Vice Leader at once: the previous holder
        // keeps their history and continues as an ordinary member.
        $closed = $this->reload($vicePeriod);
        $this->assertSame(Position::VICE_LEADER, $closed->get('position'));
        $this->assertSame($this->day(10), $closed->get('dateTo'));

        $tail = $this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)->where([
            'memberId' => $incumbent->getId(),
            'dateFrom' => $this->day(10),
        ])->findOne();

        $this->assertNotNull($tail);
        $this->assertSame(Position::MEMBER, $tail->get('position'));
        $this->assertSame($this->day(200), $tail->get('dateTo'));
        $this->assertSame($plan->getId(), $tail->get('displacedById'));
    }

    public function testAnOrdinaryMemberPeriodIsNeverDisplaced(): void
    {
        $team = $this->createTeam('Member share');
        $first = $this->createMember('share.first');
        $second = $this->createMember('share.second');
        $editor = $this->createEditor();

        $period = $editor->create($first->getId(), $team->getId(), Position::MEMBER, '2026-05-01', $this->day(200), Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $editor->create($second->getId(), $team->getId(), Position::MEMBER, $this->day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $untouched = $this->reload($period);
        $this->assertSame(Position::MEMBER, $untouched->get('position'));
        $this->assertSame($this->day(200), $untouched->get('dateTo'));
    }

    public function testReturningLeaderPlanToDraftRestoresTheIncumbent(): void
    {
        $team = $this->createTeam('Restore displaced leader');
        $incumbent = $this->createMember('restore.leader');
        $successor = $this->createMember('restore.successor');
        $editor = $this->createEditor();

        $leader = $editor->create($incumbent->getId(), $team->getId(), Position::LEADER,
            '2026-05-01', $this->day(200), Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $plan = $editor->create($successor->getId(), $team->getId(), Position::LEADER,
            $this->day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $editor->returnToDraft($plan);

        $this->assertSame(Status::DRAFT, $this->reload($plan)->get('status'));
        $this->assertSame($this->day(200), $this->reload($leader)->get('dateTo'));
        $this->assertSame(0, $this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)->where([
            'memberId' => $incumbent->getId(),
            'dateFrom' => $this->day(10),
            'position' => Position::MEMBER,
        ])->count());
    }

    public function testConfirmingAndRedraftingLeaderPlanDisplacesAndRestores(): void
    {
        $team = $this->createTeam('Edit displaced leader');
        $incumbent = $this->createMember('edit.leader');
        $successor = $this->createMember('edit.successor');
        $editor = $this->createEditor();

        $leader = $editor->create($incumbent->getId(), $team->getId(), Position::LEADER,
            '2026-05-01', null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $plan = $editor->create($successor->getId(), $team->getId(), Position::LEADER,
            $this->day(10), null, Status::DRAFT, null, Position::DEFAULT_LIST);

        $editor->update($plan, Position::LEADER, $team->getId(), $this->day(10), null,
            Status::CONFIRMED, null);
        $this->assertSame($this->day(10), $this->reload($leader)->get('dateTo'));

        $editor->update($plan, Position::LEADER, $team->getId(), $this->day(10), null,
            Status::DRAFT, null);
        $this->assertNull($this->reload($leader)->get('dateTo'));
    }

    public function testDeletingLeaderPlanRestoresFutureIncumbentPosition(): void
    {
        $team = $this->createTeam('Restore future leader');
        $incumbent = $this->createMember('future.leader');
        $successor = $this->createMember('earlier.successor');
        $editor = $this->createEditor();

        $future = $editor->create($incumbent->getId(), $team->getId(), Position::LEADER,
            $this->day(40), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $plan = $editor->create($successor->getId(), $team->getId(), Position::LEADER,
            $this->day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $this->assertSame(Position::MEMBER, $this->reload($future)->get('position'));

        $editor->delete($plan);

        $this->assertSame(Position::LEADER, $this->reload($future)->get('position'));
    }

    /**
     * D1 gap: a split's tail can be removed independently of the plan that
     * created it (for example by CleanupMissedDrafts, after an unrelated
     * manual CRM change on the incumbent reset that tail to Draft). If the
     * promotion plan is later undone, the incumbent must still get their
     * period back instead of staying truncated forever with no repair path.
     */
    public function testDeletingLeaderPlanRestoresIncumbentEvenWhenItsDisplacementTailWasAlreadyRemoved(): void
    {
        $team = $this->createTeam('Restore after tail removed');
        $incumbent = $this->createMember('tailless.incumbent');
        $successor = $this->createMember('tailless.successor');
        $editor = $this->createEditor();

        $leader = $editor->create($incumbent->getId(), $team->getId(), Position::LEADER,
            '2026-05-01', $this->day(200), Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $plan = $editor->create($successor->getId(), $team->getId(), Position::LEADER,
            $this->day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $this->assertSame($this->day(10), $this->reload($leader)->get('dateTo'));

        $tail = $this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)->where([
            'memberId' => $incumbent->getId(),
            'dateFrom' => $this->day(10),
        ])->findOne();
        $this->assertNotNull($tail);

        // Simulate the tail having already been removed independently,
        // before the promotion plan itself is ever touched again.
        $this->getEntityManager()->removeEntity($tail);

        $editor->delete($plan);

        $this->assertSame($this->day(200), $this->reload($leader)->get('dateTo'));
    }

    /**
     * The dateTo guard must not restore a boundary that someone else has
     * since moved for an unrelated reason: only an untouched split is undone.
     */
    public function testDeletingLeaderPlanDoesNotRestoreIncumbentWhenTailIsGoneAndBoundaryWasEditedSince(): void
    {
        $team = $this->createTeam('Keep edited boundary after tail removed');
        $incumbent = $this->createMember('tailless.edited.incumbent');
        $successor = $this->createMember('tailless.edited.successor');
        $editor = $this->createEditor();

        $leader = $editor->create($incumbent->getId(), $team->getId(), Position::LEADER,
            '2026-05-01', $this->day(200), Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $plan = $editor->create($successor->getId(), $team->getId(), Position::LEADER,
            $this->day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $tail = $this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)->where([
            'memberId' => $incumbent->getId(),
            'dateFrom' => $this->day(10),
        ])->findOne();
        $this->assertNotNull($tail);
        $this->getEntityManager()->removeEntity($tail);

        // Someone else moves the incumbent's boundary for an unrelated
        // reason after the tail is gone but before the plan is deleted.
        $leader = $this->reload($leader);
        $leader->set('dateTo', $this->day(70));
        $this->getEntityManager()->saveEntity($leader);

        $editor->delete($plan);

        $this->assertSame($this->day(70), $this->reload($leader)->get('dateTo'));
    }

    public function testDeletingLeaderPlanDoesNotOverwriteALaterIncumbentEdit(): void
    {
        $team = $this->createTeam('Keep later leader edit');
        $incumbent = $this->createMember('edited.future.leader');
        $successor = $this->createMember('edited.earlier.successor');
        $editor = $this->createEditor();

        $future = $editor->create($incumbent->getId(), $team->getId(), Position::LEADER,
            $this->day(40), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $plan = $editor->create($successor->getId(), $team->getId(), Position::LEADER,
            $this->day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $future = $this->reload($future);
        $future->set('position', Position::VICE_LEADER);
        $this->getEntityManager()->saveEntity($future);

        $editor->delete($plan);

        $this->assertSame(Position::VICE_LEADER, $this->reload($future)->get('position'));
    }

    public function testFailedLeaderDisplacementRollsBackAndCanBeRetried(): void
    {
        $team = $this->createTeam('Atomic displacement');
        $incumbent = $this->createMember('atomic.incumbent');
        $successor = $this->createMember('atomic.successor');
        $editor = $this->createEditor();

        $leader = $editor->create(
            $incumbent->getId(),
            $team->getId(),
            Position::LEADER,
            '2026-01-01',
            null,
            Status::CONFIRMED,
            null,
            Position::DEFAULT_LIST,
        );

        $incumbentCount = $this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)
            ->where(['memberId' => $incumbent->getId()])->count();
        $successorCount = $this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)
            ->where(['memberId' => $successor->getId()])->count();
        try {
            $editor->create(
                $successor->getId(),
                $team->getId(),
                Position::LEADER,
                $this->day(10),
                null,
                Status::CONFIRMED,
                null,
                [Position::LEADER, str_repeat('x', 101)],
            );
            self::fail('An overlong demotion position must fail.');
        } catch (Throwable) {
            $this->assertSame($incumbentCount, $this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)
                ->where(['memberId' => $incumbent->getId()])->count());
            $this->assertSame($successorCount, $this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)
                ->where(['memberId' => $successor->getId()])->count());
            $this->assertNull($this->reload($leader)->get('dateTo'));
            $this->assertSame(0, $this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)
                ->where([
                    'memberId' => $incumbent->getId(),
                    'dateFrom' => $this->day(10),
                ])->count());
        }

        $retry = $editor->create(
            $successor->getId(),
            $team->getId(),
            Position::LEADER,
            $this->day(10),
            null,
            Status::CONFIRMED,
            null,
            Position::DEFAULT_LIST,
        );

        $this->assertNotNull($retry->getId());
        $this->assertSame($this->day(10), $this->reload($leader)->get('dateTo'));
    }

    public function testDraftLeaderDoesNotChangeAFutureIncumbent(): void
    {
        $team = $this->createTeam('Split2');
        $incumbent = $this->createMember('incumbent.two');
        $successor = $this->createMember('successor.two');
        $editor = $this->createEditor();

        $neverTookEffect = $editor->create($incumbent->getId(), $team->getId(), Position::LEADER, $this->day(40), null, Status::DRAFT, null, Position::DEFAULT_LIST);
        $incumbentCount = $this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)
            ->where(['memberId' => $incumbent->getId()])->count();
        $editor->create($successor->getId(), $team->getId(), Position::LEADER, $this->day(10), null, Status::DRAFT, null, Position::DEFAULT_LIST);

        $edited = $this->reload($neverTookEffect);
        $this->assertSame(Position::LEADER, $edited->get('position'));
        $this->assertSame($this->day(40), $edited->get('dateFrom'));
        $this->assertSame($incumbentCount, $this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)
            ->where(['memberId' => $incumbent->getId()])->count());
    }

    public function testNonExclusivePositionsAreNotSplit(): void
    {
        $team = $this->createTeam('Members');
        $one = $this->createMember('member.one');
        $two = $this->createMember('member.two');
        $editor = $this->createEditor();

        $first = $editor->create($one->getId(), $team->getId(), Position::MEMBER, '2026-05-01', null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $editor->create($two->getId(), $team->getId(), Position::MEMBER, $this->day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $this->assertNull($this->reload($first)->get('dateTo'));
    }

    public function testAPositionChangeWithinOneTeamClosesTheOldPeriod(): void
    {
        $team = $this->createTeam('Promote');
        $member = $this->createMember('promote.one');
        $editor = $this->createEditor();

        $asMember = $editor->create($member->getId(), $team->getId(), Position::MEMBER, '2026-01-01', null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $asLeader = $editor->create($member->getId(), $team->getId(), Position::LEADER, $this->day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $this->assertSame($this->day(10), $this->reload($asMember)->get('dateTo'));
        $this->assertSame($asMember->getId(), $asLeader->get('supersedesId'));
    }

    public function testReDroppingSomeoneWhereTheyAlreadyAreClosesNothing(): void
    {
        $team = $this->createTeam('Idle');
        $member = $this->createMember('idle.one');
        $editor = $this->createEditor();

        $existing = $editor->create($member->getId(), $team->getId(), Position::MEMBER, '2026-01-01', null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $again = $editor->create($member->getId(), $team->getId(), Position::MEMBER, $this->day(10), null, Status::DRAFT, null, Position::DEFAULT_LIST);

        $this->assertNull($this->reload($existing)->get('dateTo'));
        $this->assertNull($again->get('supersedesId'));
    }

    public function testCloseAtCutsAtTheGivenDateAndLinksNoSuccessor(): void
    {
        $team = $this->createTeam('Removal');
        $member = $this->createMember('removed.one');
        $editor = $this->createEditor();
        $period = $editor->create($member->getId(), $team->getId(), Position::MEMBER, '2026-01-01', null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $editor->closeAt($period, '2026-09-15', null);

        $this->assertSame('2026-09-15', $this->reload($period)->get('dateTo'));
    }

    public function testDeletingADraftLeavesTheConfirmedPeriodUnchanged(): void
    {
        $alpha = $this->createTeam('Restore');
        $bravo = $this->createTeam('RestoreTo');
        $member = $this->createMember('restore.one');
        $editor = $this->createEditor();

        $inAlpha = $editor->create($member->getId(), $alpha->getId(), Position::MEMBER, '2026-01-01', null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $plan = $editor->create($member->getId(), $bravo->getId(), Position::MEMBER, $this->day(10), null, Status::DRAFT, null, Position::DEFAULT_LIST);

        $editor->delete($plan);

        $this->assertNull($this->reload($inAlpha)->get('dateTo'));
        $this->assertNull($this->getEntityManager()->getEntityById(self::ASSIGNMENT, $plan->getId()));
    }

    public function testDeletingAPlanWhoseClosedPeriodIsGoneIsANoOp(): void
    {
        $alpha = $this->createTeam('Chain');
        $bravo = $this->createTeam('ChainTo');
        $member = $this->createMember('chain.one');
        $editor = $this->createEditor();

        $inAlpha = $editor->create($member->getId(), $alpha->getId(), Position::MEMBER, '2026-01-01', null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $plan = $editor->create($member->getId(), $bravo->getId(), Position::MEMBER, $this->day(10), null, Status::DRAFT, null, Position::DEFAULT_LIST);

        $this->getEntityManager()->removeEntity($inAlpha);
        $editor->delete($plan);

        $this->assertNull($this->getEntityManager()->getEntityById(self::ASSIGNMENT, $plan->getId()));
    }

    /** @return Entity[] Confirmed rows of one person in one team and position. */
    private function confirmedIdentical(User $user, Team $team, string $position): array
    {
        $list = [];

        foreach ($this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)->where([
            'memberId' => $user->getId(),
            'teamId' => $team->getId(),
            'position' => $position,
            'status' => Status::CONFIRMED,
        ])->find() as $row) {
            $list[] = $row;
        }

        return $list;
    }

    /** @param Entity[] $rows */
    private function assertNoOverlap(array $rows): void
    {
        foreach ($rows as $i => $a) {
            foreach ($rows as $j => $b) {
                if ($j <= $i) {
                    continue;
                }

                $sameSlot = $a->get('teamId') === $b->get('teamId') && $a->get('position') === $b->get('position');
                $overlap = ($a->get('dateTo') === null || $a->get('dateTo') > $b->get('dateFrom')) &&
                    ($b->get('dateTo') === null || $b->get('dateTo') > $a->get('dateFrom'));

                $this->assertFalse($sameSlot && $overlap, sprintf(
                    'Overlapping confirmed periods %s [%s, %s) and %s [%s, %s).',
                    $a->get('position'), $a->get('dateFrom'), $a->get('dateTo') ?? 'open',
                    $b->get('position'), $b->get('dateFrom'), $b->get('dateTo') ?? 'open'
                ));
            }
        }
    }

    private function day(int $n): string
    {
        return (new \DateTimeImmutable("+$n days"))->format('Y-m-d');
    }

    public function testAnIdenticalConfirmedLeaderOnTheSameDateDoesNotDuplicateThePerson(): void
    {
        $team = $this->createTeam('T08 duplicate');
        $holder = $this->createMember('t08.dup');
        $editor = $this->createEditor();

        $first = $editor->create($holder->getId(), $team->getId(), Position::LEADER, $this->day(5), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $second = $editor->create($holder->getId(), $team->getId(), Position::LEADER, $this->day(5), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $this->assertNoOverlap($this->confirmedIdentical($holder, $team, Position::LEADER));

        // Resetting one copy restores exactly one open leader period, not two.
        $editor->returnToDraft($this->reload($second));
        $rows = $this->confirmedIdentical($holder, $team, Position::LEADER);
        $this->assertCount(1, $rows);
        $this->assertSame($first->getId(), $rows[0]->getId());
        $this->assertNull($rows[0]->get('dateTo'));
    }

    public function testALaterIdenticalConfirmedPeriodClosesTheCoveringOne(): void
    {
        $team = $this->createTeam('T08 later');
        $holder = $this->createMember('t08.later');
        $editor = $this->createEditor();

        $first = $editor->create($holder->getId(), $team->getId(), Position::LEADER, $this->day(1), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $second = $editor->create($holder->getId(), $team->getId(), Position::LEADER, $this->day(5), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $this->assertNoOverlap($this->confirmedIdentical($holder, $team, Position::LEADER));
        $this->assertSame($this->day(5), $this->reload($first)->get('dateTo'));
        $this->assertSame($first->getId(), $this->reload($second)->get('supersedesId'));
    }

    public function testConfirmingAnUpdateIntoAnIdenticalPeriodDoesNotOverlapIt(): void
    {
        $team = $this->createTeam('T08 update');
        $holder = $this->createMember('t08.update');
        $editor = $this->createEditor();

        $first = $editor->create($holder->getId(), $team->getId(), Position::LEADER, $this->day(1), $this->day(20), Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $plan = $editor->create($holder->getId(), $team->getId(), Position::MEMBER, $this->day(10), null, Status::DRAFT, null, Position::DEFAULT_LIST);

        $editor->update($this->reload($plan), Position::LEADER, $team->getId(), $this->day(10), null, Status::CONFIRMED, null);

        $this->assertNoOverlap($this->confirmedIdentical($holder, $team, Position::LEADER));
        $this->assertSame($this->day(10), $this->reload($first)->get('dateTo'));
    }

    public function testAnEarlierIdenticalConfirmedPeriodThatWouldOverlapALaterOneIsRejected(): void
    {
        $team = $this->createTeam('T08 earlier');
        $holder = $this->createMember('t08.earlier');
        $editor = $this->createEditor();

        $editor->create($holder->getId(), $team->getId(), Position::LEADER, $this->day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        try {
            $editor->create($holder->getId(), $team->getId(), Position::LEADER, $this->day(5), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
            $this->fail('An overlapping identical confirmed period was accepted.');
        } catch (BadRequest) {
        }

        $this->assertCount(1, $this->confirmedIdentical($holder, $team, Position::LEADER));
    }

    public function testDeactivationReturnsEveryFuturePlanIncludingDisplacementTailsToDraft(): void
    {
        $team = $this->createTeam('P12 team');
        $user = $this->createMember('p12.user');
        $user->set('isActive', true);
        $this->getEntityManager()->saveEntity($user);
        $other = $this->createMember('p12.other');
        $third = $this->createMember('p12.third');
        $editor = $this->createEditor();
        $day = static fn (int $n): string => (new \DateTimeImmutable("+$n days"))->format('Y-m-d');

        // T08 tails: the user's Supervisor and Vice Leader plans are each
        // split by someone else's later promotion.
        $editor->create($user->getId(), $team->getId(), Position::SUPERVISOR, $day(5), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $otherPlan = $editor->create($other->getId(), $team->getId(), Position::SUPERVISOR, $day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $editor->create($user->getId(), $team->getId(), Position::VICE_LEADER, $day(20), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $thirdPlan = $editor->create($third->getId(), $team->getId(), Position::VICE_LEADER, $day(21), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $user->set('isActive', false);
        $this->getEntityManager()->saveEntity($user);

        $snapshot = function () use ($user): array {
            $rows = [];
            foreach ($this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)
                ->where(['memberId' => $user->getId()])->order('dateFrom')->order('id')->find() as $row) {
                $rows[] = [$row->getId(), $row->get('position'), $row->get('dateFrom'), $row->get('dateTo'), $row->get('status')];
            }
            return $rows;
        };

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $confirmed = [];

        foreach ($this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)
            ->where(['memberId' => $user->getId(), 'status' => Status::CONFIRMED])->find() as $row) {
            $confirmed[] = $row;
            $this->assertLessThanOrEqual($today, (string) $row->get('dateFrom'),
                'A future confirmed plan survived deactivation: ' . $row->get('position') . ' ' . $row->get('dateFrom'));
        }

        $this->assertNoOverlap($confirmed);
        $this->assertSame(Status::CONFIRMED, $this->reload($otherPlan)->get('status'));
        $this->assertSame(Status::CONFIRMED, $this->reload($thirdPlan)->get('status'));

        // A repeated deactivation signal changes nothing.
        $before = $snapshot();
        $this->getInjectableFactory()->create(CrmChangeHandler::class)->handle($user->getId());
        $this->assertSame($before, $snapshot());
    }

    /** Confirmed holders of each exclusive position in one team never overlap. */
    private function assertExclusiveHoldersDoNotOverlap(Team $team, string $context = ''): void
    {
        $rows = [];

        foreach ($this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)->where([
            'teamId' => $team->getId(),
            'status' => Status::CONFIRMED,
            'position' => [Position::SUPERVISOR, Position::LEADER, Position::VICE_LEADER],
        ])->find() as $row) {
            if ($row->get('dateTo') !== null && $row->get('dateTo') <= $row->get('dateFrom')) {
                continue;
            }
            $rows[] = $row;
        }

        foreach ($rows as $i => $a) {
            foreach ($rows as $j => $b) {
                if ($j <= $i || $a->get('position') !== $b->get('position')) {
                    continue;
                }
                $overlap = ($a->get('dateTo') === null || $a->get('dateTo') > $b->get('dateFrom')) &&
                    ($b->get('dateTo') === null || $b->get('dateTo') > $a->get('dateFrom'));
                $this->assertFalse($overlap, sprintf(
                    '%sTwo confirmed %s holders overlap: %s [%s, %s) and %s [%s, %s).',
                    $context, $a->get('position'),
                    $a->get('memberId'), $a->get('dateFrom'), $a->get('dateTo') ?? 'open',
                    $b->get('memberId'), $b->get('dateFrom'), $b->get('dateTo') ?? 'open'
                ));
            }
        }
    }

    private function activeMember(string $userName): User
    {
        $user = $this->createMember($userName);
        $user->set('isActive', true);
        $this->getEntityManager()->saveEntity($user);

        return $user;
    }

    public function testAManualCrmChangeOnADisplacedLeaderNeverLeavesTwoConfirmedLeaders(): void
    {
        $team = $this->createTeam('T08 chain');
        $other = $this->createTeam('T08 chain other');
        $editor = $this->createEditor();
        $users = [];
        foreach ([5, 6, 7, 8] as $n) {
            $users[$n] = $this->activeMember("t08.chain.u$n");
        }

        // final-A-858a8405: u5..u8 Leader on the same day, then u5 again later.
        foreach ([5, 6, 7, 8] as $n) {
            $editor->create($users[$n]->getId(), $team->getId(), Position::LEADER, $this->day(10), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        }
        $editor->create($users[5]->getId(), $team->getId(), Position::LEADER, $this->day(20), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $this->assertExclusiveHoldersDoNotOverlap($team, 'before: ');

        // Manual CRM change on the displaced holder u8.
        $this->getEntityManager()->getRelation($other, 'users')->relate($users[8], ['role' => Position::MEMBER]);
        $users[8]->set('defaultTeamId', $other->getId());
        $this->getEntityManager()->saveEntity($users[8]);

        $this->assertExclusiveHoldersDoNotOverlap($team, 'after manual change: ');

        $users[7]->set('isActive', false);
        $this->getEntityManager()->saveEntity($users[7]);
        $this->assertExclusiveHoldersDoNotOverlap($team, 'after deactivation: ');
    }

    public function testRandomPlanSequencesKeepExclusivePositionsSingleHeld(): void
    {
        mt_srand(20260930);
        $team = $this->createTeam('T08 random');
        $other = $this->createTeam('T08 random other');
        $editor = $this->createEditor();
        $users = [];
        for ($n = 0; $n < 5; $n++) {
            $users[] = $this->activeMember("t08.random.u$n");
        }
        $positions = [Position::SUPERVISOR, Position::LEADER, Position::VICE_LEADER, Position::MEMBER];

        for ($step = 0; $step < 40; $step++) {
            $user = $users[mt_rand(0, 4)];
            $op = mt_rand(0, 9);
            $label = "step $step op $op: ";

            if ($op < 6) {
                try {
                    $editor->create($user->getId(), $team->getId(), $positions[mt_rand(0, 3)], $this->day(mt_rand(1, 30)), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
                } catch (BadRequest) {
                }
            } elseif ($op < 8) {
                $plans = $this->getEntityManager()->getRDBRepository(self::ASSIGNMENT)->where([
                    'memberId' => $user->getId(), 'status' => Status::CONFIRMED, 'appliedAt' => null,
                ])->find();
                foreach ($plans as $plan) {
                    $op === 6 ? $editor->returnToDraft($plan) : $editor->delete($plan);
                    break;
                }
            } else {
                $fresh = $this->getEntityManager()->getEntityById(User::ENTITY_TYPE, $user->getId());
                $this->getEntityManager()->getRelation($other, 'users')->relate($fresh, ['role' => Position::MEMBER]);
                $fresh->set('defaultTeamId', $fresh->get('defaultTeamId') === $other->getId() ? null : $other->getId());
                $this->getEntityManager()->saveEntity($fresh);
            }

            $this->assertExclusiveHoldersDoNotOverlap($team, $label);
        }
    }

    /** final-A 0e8c8872: a bounded Leader plan leaves later, non-overlapping Leader plans untouched. */
    public function testABoundedLeaderPlanDoesNotDemoteALaterNonOverlappingLeader(): void
    {
        $team = $this->createTeam('T08 bounded');
        $editor = $this->createEditor();
        $k1 = $this->activeMember('t08.bounded.k1');
        $m1 = $this->activeMember('t08.bounded.m1');
        $k2 = $this->activeMember('t08.bounded.k2');

        $atUntil = $editor->create($k1->getId(), $team->getId(), Position::LEADER, $this->day(40), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        $later = $editor->create($m1->getId(), $team->getId(), Position::LEADER, $this->day(42), null, Status::CONFIRMED, null, Position::DEFAULT_LIST);
        // Re-read: m1's plan split k1 at +42.
        $editor->create($k2->getId(), $team->getId(), Position::LEADER, $this->day(31), $this->day(40), Status::CONFIRMED, null, Position::DEFAULT_LIST);

        $this->assertSame(Position::LEADER, $this->reload($atUntil)->get('position'));
        $this->assertSame(Position::LEADER, $this->reload($later)->get('position'));
        $this->assertExclusiveHoldersDoNotOverlap($team);
    }
}
