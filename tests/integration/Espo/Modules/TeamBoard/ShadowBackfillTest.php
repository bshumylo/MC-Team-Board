<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Shadow\Backfill;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;
use tests\integration\Core\BaseTestCase;

class ShadowBackfillTest extends BaseTestCase
{
    private const ASSIGNMENT = 'TeamBoardAssignment';
    private const MEMBER = 'TeamBoardMember';
    private const SQUAD = 'TeamBoardSquad';

    private function entityManager(): EntityManager
    {
        return $this->getContainer()->getByClass(EntityManager::class);
    }

    private function createBoardUser(string $userName, string $lastName): User
    {
        /** @var User */
        return $this->entityManager()->createEntity(User::ENTITY_TYPE, [
            'userName' => $userName,
            'lastName' => $lastName,
            'type' => User::TYPE_REGULAR,
            'isActive' => true,
        ]);
    }

    private function createTeam(string $name, array $positionList): Team
    {
        /** @var Team */
        return $this->entityManager()->createEntity(Team::ENTITY_TYPE, [
            'name' => $name,
            'positionList' => $positionList,
        ]);
    }

    private function createAssignment(
        string $memberId,
        string $teamId,
        array $values = []
    ): Entity {
        return $this->entityManager()->createEntity(self::ASSIGNMENT, array_merge([
            'memberId' => $memberId,
            'teamId' => $teamId,
            'position' => 'Member',
            'dateFrom' => '2026-01-01',
            'dateTo' => null,
            'status' => 'confirmed',
            'note' => null,
            'appliedAt' => '2026-01-01 12:00:00',
        ], $values));
    }

    /**
     * @return array<string, mixed>
     */
    private function legacySnapshot(Entity $assignment): array
    {
        $keys = [
            'memberId',
            'teamId',
            'position',
            'dateFrom',
            'dateTo',
            'status',
            'note',
            'appliedAt',
            'supersedesId',
            'supersededDateTo',
        ];
        $snapshot = [];

        foreach ($keys as $key) {
            $snapshot[$key] = $assignment->get($key);
        }

        return $snapshot;
    }

    private function reloadAssignment(string $id): Entity
    {
        $assignment = $this->entityManager()->getEntityById(self::ASSIGNMENT, $id);
        $this->assertNotNull($assignment);

        return $assignment;
    }

    public function testBackfillPreservesAssignmentsAndCreatesNoProfileCopies(): void
    {
        $alice = $this->createBoardUser('shadow.alice', 'Alice Original');
        $bob = $this->createBoardUser('shadow.bob', 'Bob Original');
        $alpha = $this->createTeam('Alpha Original', ['Leader', 'Member']);
        $bravo = $this->createTeam('Bravo Original', ['Supervisor', 'Member']);

        $first = $this->createAssignment($alice->getId(), $alpha->getId(), [
            'position' => 'Leader',
            'dateTo' => '2026-06-01',
            'note' => 'Keep this note',
            'supersededDateTo' => '2026-12-31',
        ]);
        $second = $this->createAssignment($bob->getId(), $bravo->getId(), [
            'dateFrom' => '2026-06-01',
            'status' => 'draft',
            'appliedAt' => null,
            'supersedesId' => $first->getId(),
        ]);
        $before = [
            $first->getId() => $this->legacySnapshot($first),
            $second->getId() => $this->legacySnapshot($second),
        ];

        // New users now also have a factual Reserve period. Backfill must
        // preserve every existing row, including those initial CRM facts.
        $assignmentCount = $this->entityManager()->getRDBRepository(self::ASSIGNMENT)->count();
        $report = $this->getInjectableFactory()->create(Backfill::class)->run();

        $this->assertGreaterThanOrEqual(2, $report->memberCount);
        $this->assertGreaterThanOrEqual(2, $report->squadCount);
        $this->assertSame($assignmentCount, $report->assignmentCount);
        $this->assertSame($assignmentCount, $this->entityManager()->getRDBRepository(self::ASSIGNMENT)->count());

        foreach ($before as $id => $snapshot) {
            $reloaded = $this->reloadAssignment($id);
            $this->assertSame($snapshot, $this->legacySnapshot($reloaded));
            $this->assertNotNull($reloaded->get('boardMemberId'));
            $this->assertNotNull($reloaded->get('boardSquadId'));
        }

        $member = $this->entityManager()->getRDBRepository(self::MEMBER)
            ->where(['userId' => $alice->getId()])
            ->findOne();
        $squad = $this->entityManager()->getRDBRepository(self::SQUAD)
            ->where(['teamId' => $alpha->getId()])
            ->findOne();

        $this->assertNotNull($member);
        $this->assertNotNull($squad);
        $this->assertNull($member->get('name'));
        $this->assertNull($member->get('photoId'));
        $this->assertNull($squad->get('name'));
        $this->assertNull($squad->get('positionList'));
    }

    public function testBackfillIsIdempotentAndResolversReadLinkedValuesLive(): void
    {
        $user = $this->createBoardUser('shadow.live', 'Before');
        $team = $this->createTeam('Before Team', ['Leader', 'Member']);
        $assignment = $this->createAssignment($user->getId(), $team->getId());
        $backfill = $this->getInjectableFactory()->create(Backfill::class);

        $backfill->run();
        $firstMemberId = $this->reloadAssignment($assignment->getId())->get('boardMemberId');
        $firstSquadId = $this->reloadAssignment($assignment->getId())->get('boardSquadId');
        $backfill->run();

        $this->assertSame(1, $this->entityManager()->getRDBRepository(self::MEMBER)
            ->where(['userId' => $user->getId()])->count());
        $this->assertSame(1, $this->entityManager()->getRDBRepository(self::SQUAD)
            ->where(['teamId' => $team->getId()])->count());
        $this->assertSame($firstMemberId, $this->reloadAssignment($assignment->getId())->get('boardMemberId'));
        $this->assertSame($firstSquadId, $this->reloadAssignment($assignment->getId())->get('boardSquadId'));

        $user->set('lastName', 'After');
        $team->set([
            'name' => 'After Team',
            'positionList' => ['Supervisor', 'Leader', 'Member'],
        ]);
        $this->entityManager()->saveEntity($user);
        $this->entityManager()->saveEntity($team);

        $registry = $this->getInjectableFactory()->create(Registry::class);
        $member = $this->entityManager()->getEntityById(self::MEMBER, $firstMemberId);
        $squad = $this->entityManager()->getEntityById(self::SQUAD, $firstSquadId);
        $this->assertNotNull($member);
        $this->assertNotNull($squad);

        $resolvedMember = $registry->resolveMember($member);
        $resolvedSquad = $registry->resolveSquad($squad);
        $this->assertSame('After', $resolvedMember->name);
        $this->assertSame('After Team', $resolvedSquad->name);
        $this->assertSame(['Supervisor', 'Leader', 'Member'], $resolvedSquad->positionList);
        $this->assertNull($member->get('name'));
        $this->assertNull($squad->get('name'));
        $this->assertNull($squad->get('positionList'));
    }

    public function testBackfillRollsBackAllMappingsWhenALegacyTargetIsMissing(): void
    {
        $team = $this->createTeam('Rollback Team', ['Leader', 'Member']);
        $assignment = $this->createAssignment('missing-user-id', $team->getId());
        $memberCountBefore = $this->entityManager()->getRDBRepository(self::MEMBER)->count();
        $squadCountBefore = $this->entityManager()->getRDBRepository(self::SQUAD)->count();

        try {
            $this->getInjectableFactory()->create(Backfill::class)->run();
            $this->fail('Backfill must reject an orphan legacy assignment.');
        }
        catch (RuntimeException) {
            $this->assertSame($memberCountBefore, $this->entityManager()->getRDBRepository(self::MEMBER)->count());
            $this->assertSame($squadCountBefore, $this->entityManager()->getRDBRepository(self::SQUAD)->count());
            $this->assertNull($this->reloadAssignment($assignment->getId())->get('boardMemberId'));
            $this->assertNull($this->reloadAssignment($assignment->getId())->get('boardSquadId'));
        }
    }

    public function testBackfillCanRetryAfterTheMissingLegacyUserIsRestored(): void
    {
        $team = $this->createTeam('Retry Team', ['Leader', 'Member']);
        $assignment = $this->createAssignment('missing-user-id', $team->getId());

        try {
            $this->getInjectableFactory()->create(Backfill::class)->run();
            $this->fail('Backfill must reject an orphan legacy assignment.');
        } catch (RuntimeException) {
            $user = $this->createBoardUser('shadow.retry', 'Retry User');
            $assignment->set('memberId', $user->getId());
            $this->entityManager()->saveEntity($assignment);
        }

        $assignmentCount = $this->entityManager()->getRDBRepository(self::ASSIGNMENT)->count();
        $report = $this->getInjectableFactory()->create(Backfill::class)->run();
        $reloaded = $this->reloadAssignment($assignment->getId());

        $this->assertSame($assignmentCount, $report->assignmentCount);
        $this->assertSame($assignmentCount, $this->entityManager()->getRDBRepository(self::ASSIGNMENT)->count());
        $this->assertNotNull($reloaded->get('boardMemberId'));
        $this->assertNotNull($reloaded->get('boardSquadId'));
        $this->assertSame(1, $this->entityManager()->getRDBRepository(self::MEMBER)
            ->where(['userId' => $user->getId()])->count());
        $this->assertSame(1, $this->entityManager()->getRDBRepository(self::SQUAD)
            ->where(['teamId' => $team->getId()])->count());
    }
}
