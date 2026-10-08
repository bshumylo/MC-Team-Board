<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use tests\integration\Core\BaseTestCase;

class TimelineQueryTest extends BaseTestCase
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

    /**
     * @param array<string, mixed> $extra
     */
    private function createAssignment(
        User $member,
        Team $team,
        string $dateFrom,
        ?string $dateTo,
        array $extra = []
    ): Entity {

        $registry = $this->getInjectableFactory()->create(Registry::class);
        $boardMember = $registry->memberForUser($member->getId());
        $boardSquad = $registry->squadForTeam($team->getId());

        return $this->getEntityManager()->createEntity(self::ASSIGNMENT, array_merge([
            'boardMemberId' => $boardMember->getId(),
            'boardSquadId' => $boardSquad->getId(),
            'memberId' => $member->getId(),
            'teamId' => $team->getId(),
            'position' => 'Member',
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'status' => Status::DRAFT,
        ], $extra));
    }

    private function createQuery(): AssignmentQuery
    {
        return $this->getInjectableFactory()->create(AssignmentQuery::class);
    }

    public function testFindCoveringDateRespectsExclusiveEnd(): void
    {
        $team = $this->createTeam('Alpha');
        $member = $this->createMember('alpha.one');

        $this->createAssignment($member, $team, '2026-03-01', '2026-04-01');

        $query = $this->createQuery();

        $this->assertCount(0, $query->findCoveringDate('2026-02-28'));
        $this->assertCount(1, $query->findCoveringDate('2026-03-01'));
        $this->assertCount(1, $query->findCoveringDate('2026-03-31'));
        $this->assertCount(0, $query->findCoveringDate('2026-04-01'));
    }

    public function testFindCoveringDateIncludesOpenEndedPeriods(): void
    {
        $team = $this->createTeam('Bravo');
        $member = $this->createMember('bravo.one');

        $assignment = $this->createAssignment($member, $team, '2026-03-01', null);

        $found = $this->createQuery()->findCoveringDate('2099-01-01');
        $this->assertContains($assignment->getId(), array_map(static fn ($row) => $row->getId(), $found));
        $this->assertCount(1, array_filter($found, static fn ($row) => $row->get('teamId') === $team->getId()));
    }

    public function testFindCoveringDateFiltersByTeam(): void
    {
        $alpha = $this->createTeam('Alpha');
        $bravo = $this->createTeam('Bravo');
        $member = $this->createMember('shared.one');

        $this->createAssignment($member, $alpha, '2026-03-01', null);
        $this->createAssignment($member, $bravo, '2026-03-01', null);

        $query = $this->createQuery();

        $this->assertCount(2, $query->findCoveringDate('2026-03-15'));
        $this->assertCount(1, $query->findCoveringDate('2026-03-15', [$alpha->getId()]));
    }

    public function testFindInRangeReturnsPeriodsThatTouchTheRange(): void
    {
        $team = $this->createTeam('Charlie');
        $member = $this->createMember('charlie.one');

        $this->createAssignment($member, $team, '2025-01-01', '2026-01-01');
        $this->createAssignment($member, $team, '2026-06-01', '2026-07-01');
        $this->createAssignment($member, $team, '2027-01-01', null);

        $found = $this->createQuery()->findInRange('2026-03-01', '2026-09-01');

        $this->assertCount(1, $found);
        $this->assertSame('2026-06-01', $found[0]->get('dateFrom'));
    }

    public function testFindDueSelectsOnlyConfirmedStartedAndUnapplied(): void
    {
        $team = $this->createTeam('Delta');
        $member = $this->createMember('delta.one');

        $this->createAssignment($member, $team, '2026-01-01', null, ['status' => Status::CONFIRMED]);
        $this->createAssignment($member, $team, '2026-01-01', null, ['status' => Status::DRAFT]);
        $this->createAssignment($member, $team, '2099-01-01', null, ['status' => Status::CONFIRMED]);
        $this->createAssignment($member, $team, '2026-01-01', null, [
            'status' => Status::CONFIRMED,
            'appliedAt' => '2026-01-01 03:00:00',
        ]);

        $due = $this->createQuery()->findDue('2026-09-01');

        $this->assertCount(1, $due);
        $this->assertSame(Status::CONFIRMED, $due[0]->get('status'));
        $this->assertNull($due[0]->get('appliedAt'));
    }

    public function testFindMissedDraftsSparesADraftDatedToday(): void
    {
        $team = $this->createTeam('Echo');
        $member = $this->createMember('echo.one');

        $this->createAssignment($member, $team, '2026-08-31', null);
        $this->createAssignment($member, $team, '2026-09-01', null);
        $this->createAssignment($member, $team, '2026-09-02', null);

        $missed = $this->createQuery()->findMissedDrafts('2026-09-01');

        $this->assertCount(1, $missed);
        $this->assertSame('2026-08-31', $missed[0]->get('dateFrom'));
    }

    public function testFindUpcomingDraftsUsesAnInclusiveWindow(): void
    {
        $team = $this->createTeam('Foxtrot');
        $member = $this->createMember('foxtrot.one');

        $this->createAssignment($member, $team, '2026-09-05', null);
        $this->createAssignment($member, $team, '2026-09-08', null);
        $this->createAssignment($member, $team, '2026-09-09', null);
        $this->createAssignment($member, $team, '2026-09-05', null, ['status' => Status::CONFIRMED]);

        $upcoming = $this->createQuery()->findUpcomingDrafts('2026-09-01', 7);

        $this->assertCount(2, $upcoming);
    }
}
