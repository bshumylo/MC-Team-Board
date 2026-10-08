<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Jobs\CleanupMissedDrafts;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use tests\integration\Core\BaseTestCase;

class CleanupMissedDraftsTest extends BaseTestCase
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
    private function raw(User $member, Team $team, string $dateFrom, array $extra = []): Entity
    {
        return $this->getEntityManager()->createEntity(self::ASSIGNMENT, array_merge([
            'memberId' => $member->getId(),
            'teamId' => $team->getId(),
            'position' => Position::MEMBER,
            'dateFrom' => $dateFrom,
            'dateTo' => null,
            'status' => Status::DRAFT,
        ], $extra));
    }

    private function exists(Entity $entity): bool
    {
        return $this->getEntityManager()
            ->getEntityById(self::ASSIGNMENT, $entity->getId()) !== null;
    }

    private function runJob(): void
    {
        $this->getInjectableFactory()->create(CleanupMissedDrafts::class)->run();
    }

    public function testAMissedDraftIsRemoved(): void
    {
        $team = $this->createTeam('Missed');
        $member = $this->createMember('missed.one');

        $draft = $this->raw($member, $team, '2020-01-01');

        $this->runJob();

        $this->assertFalse($this->exists($draft));
    }

    public function testADraftDatedTodaySurvivesItsOwnDay(): void
    {
        $team = $this->createTeam('Today');
        $member = $this->createMember('today.one');

        $draft = $this->raw($member, $team, date('Y-m-d'));

        $this->runJob();

        $this->assertTrue($this->exists($draft));
    }

    public function testConfirmedAndAppliedAndOpenEndedPeriodsAreSpared(): void
    {
        $team = $this->createTeam('Spared');
        $member = $this->createMember('spared.one');

        $confirmed = $this->raw($member, $team, '2020-01-01', ['status' => Status::CONFIRMED]);
        $applied = $this->raw($member, $team, '2020-01-01', [
            'status' => Status::CONFIRMED,
            'appliedAt' => '2020-01-01 03:00:00',
        ]);
        $longRunning = $this->raw($member, $team, '2019-05-01', ['status' => Status::CONFIRMED]);

        $this->runJob();

        $this->assertTrue($this->exists($confirmed));
        $this->assertTrue($this->exists($applied));
        $this->assertTrue($this->exists($longRunning));
    }

    public function testDeletingAMissedDraftReopensThePeriodItClosed(): void
    {
        $alpha = $this->createTeam('StayAlpha');
        $bravo = $this->createTeam('StayBravo');
        $member = $this->createMember('stay.one');

        $editor = $this->getInjectableFactory()->create(AssignmentEditor::class);

        $inAlpha = $editor->create(
            $member->getId(), $alpha->getId(), Position::MEMBER,
            '2019-01-01', null, Status::CONFIRMED, null, Position::DEFAULT_LIST
        );

        $plan = $editor->create(
            $member->getId(), $bravo->getId(), Position::MEMBER,
            '2020-10-01', null, Status::DRAFT, null, Position::DEFAULT_LIST
        );

        $this->runJob();

        $this->assertFalse($this->exists($plan));

        $reloaded = $this->getEntityManager()->getEntityById(self::ASSIGNMENT, $inAlpha->getId());

        $this->assertNotNull($reloaded);
        $this->assertNull($reloaded->get('dateTo'));
    }

    public function testAMemberWhoseOwnPeriodEndedLandsInReserve(): void
    {
        $alpha = $this->createTeam('LeaveAlpha');
        $member = $this->createMember('leave.one');

        // A stint explicitly bounded: nothing closed it, so nothing rolls back.
        $bounded = $this->raw($member, $alpha, '2019-01-01', [
            'status' => Status::CONFIRMED,
            'dateTo' => '2020-10-01',
        ]);

        $missed = $this->raw($member, $alpha, '2020-10-01');

        $this->runJob();

        $this->assertFalse($this->exists($missed));

        $reloaded = $this->getEntityManager()->getEntityById(self::ASSIGNMENT, $bounded->getId());

        $this->assertNotNull($reloaded);
        $this->assertSame('2020-10-01', $reloaded->get('dateTo'));
    }

    public function testLaterDraftsForTheSamePersonSurvive(): void
    {
        $team = $this->createTeam('Later');
        $member = $this->createMember('later.one');

        $missed = $this->raw($member, $team, '2020-10-01');
        $future = $this->raw($member, $team, '2099-12-01');

        $this->runJob();

        $this->assertFalse($this->exists($missed));
        $this->assertTrue($this->exists($future));
    }
}
