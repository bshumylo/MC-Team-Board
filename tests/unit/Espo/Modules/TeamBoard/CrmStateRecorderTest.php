<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\Modules\TeamBoard\Tools\Timeline\CrmStateRecorder;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CrmStateRecorderTest extends TestCase
{
    private array $states = [];
    private array $entities = [];
    private array $saved = [];
    private ?string $default = 'B';
    private bool $active = true;
    private bool $related = true;

    private function entity(string $id, array $data): Entity
    {
        $this->states[$id] = $data;
        $entity = $this->createStub(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(fn ($name) => $this->states[$id][$name] ?? null);
        $entity->method('set')->willReturnCallback(function ($name, $value = null) use ($id, $entity) {
            $this->states[$id] = array_replace($this->states[$id], is_array($name) ? $name : [$name => $value]);
            return $entity;
        });
        return $this->entities[$id] = $entity;
    }

    private function recorder(array $periods): CrmStateRecorder
    {
        foreach ($periods as $id => $data) {
            $this->entity($id, $data + [
                'memberId' => 'user', 'boardMemberId' => 'person', 'status' => 'confirmed',
                'teamId' => 'A', 'boardSquadId' => 'squad-A', 'position' => 'Member',
                'appliedAt' => '2026-01-01 00:00:00', 'isCrmState' => true, 'crmIsActive' => true,
            ]);
        }
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user');
        $user->method('isActive')->willReturnCallback(fn () => $this->active);
        $user->method('get')->willReturnCallback(fn ($name) => [
            'defaultTeamId' => $this->default, 'type' => User::TYPE_REGULAR,
        ][$name] ?? null);
        $user->expects($this->never())->method('set');
        $team = $this->createStub(Team::class);
        $team->method('getId')->willReturnCallback(fn () => (string) $this->default);
        $team->method('get')->willReturnCallback(static fn ($name) => $name === 'positionList' ? ['Leader', 'Member'] : null);
        $userRepo = $this->createStub(RDBRepository::class);
        $userRepo->method('getById')->willReturn($user);
        $teamRepo = $this->createStub(RDBRepository::class);
        $teamRepo->method('getById')->willReturn($team);
        $relation = $this->createMock(RDBRelation::class);
        $relation->method('isRelated')->willReturnCallback(fn () => $this->related);
        $relation->method('getColumn')->willReturn('Member');
        $relation->expects($this->never())->method('relate');
        $relation->expects($this->never())->method('unrelate');
        $registry = $this->createStub(Registry::class);
        $member = $this->createStub(Entity::class);
        $member->method('getId')->willReturn('person');
        $registry->method('memberForUser')->willReturn($member);
        $squad = $this->createStub(Entity::class);
        $squad->method('getId')->willReturnCallback(fn () => 'squad-' . $this->default);
        $registry->method('squadForTeam')->willReturn($squad);
        $query = $this->createStub(AssignmentQuery::class);
        $query->method('findForMember')->willReturnCallback(fn () => array_values($this->entities));
        $transaction = $this->createStub(TransactionManager::class);
        $transaction->method('run')->willReturnCallback(static fn ($work) => $work());
        $em = $this->createStub(EntityManager::class);
        $em->method('getTransactionManager')->willReturn($transaction);
        $em->method('getRDBRepositoryByClass')->willReturnCallback(static fn ($class) =>
            $class === User::class ? $userRepo : $teamRepo);
        $em->method('getRelation')->willReturn($relation);
        $em->method('getNewEntity')->willReturnCallback(fn () => $this->entity('new-' . count($this->entities), []));
        $em->method('removeEntity')->willReturnCallback(function ($entity): void {
            unset($this->entities[$entity->getId()], $this->states[$entity->getId()]);
        });
        $em->method('saveEntity')->willReturnCallback(function ($entity) use ($user, $team): void {
            $this->assertNotSame($user, $entity);
            $this->assertNotSame($team, $entity);
            $this->saved[] = $entity->getId();
        });
        return new CrmStateRecorder(
            $em,
            $query,
            $registry,
            $this->createStub(\Espo\Core\Utils\DateTime::class)
        );
    }

    public function testManualMoveClosesOnlyCurrentHistoryAndRepeatedSaveDoesNotDuplicate(): void
    {
        $recorder = $this->recorder([
            'past' => ['dateFrom' => '2025-01-01', 'dateTo' => '2026-01-01', 'endedAt' => '2026-01-01 00:00:00'],
            'actual' => ['dateFrom' => '2026-01-01', 'dateTo' => null],
            'draft' => ['dateFrom' => '2026-12-01', 'dateTo' => null, 'status' => 'draft', 'appliedAt' => null],
        ]);
        $past = $this->states['past'];
        $draft = $this->states['draft'];
        $recorder->recordForUser('user', '2026-09-12');
        $this->assertSame('2026-09-12', $this->states['actual']['dateTo']);
        $this->assertNotNull($this->states['actual']['endedAt']);
        $this->assertSame($past, $this->states['past']);
        $this->assertSame($draft, $this->states['draft']);
        $this->assertSame('B', $this->states['new-3']['teamId']);
        $this->assertSame('2026-09-12', $this->states['new-3']['dateFrom']);
        $this->assertNotNull($this->states['new-3']['appliedAt']);
        $writes = count($this->saved);
        $recorder->recordForUser('user', '2026-09-12');
        $this->assertCount(4, $this->states);
        $this->assertCount($writes, $this->saved);
    }

    public function testSeveralChangesWithinOneDayKeepOnlyTheFinalState(): void
    {
        $recorder = $this->recorder(['actual' => ['dateFrom' => '2026-01-01', 'dateTo' => null]]);
        $recorder->recordForUser('user', '2026-09-12');
        $this->default = 'C';
        $recorder->recordForUser('user', '2026-09-12');
        $this->assertCount(2, $this->states);
        $this->assertSame('C', $this->states['new-1']['teamId']);
        $this->assertSame('2026-09-12', $this->states['new-1']['dateFrom']);
        $this->assertSame('A', $this->states['actual']['teamId']);
        $this->assertSame('2026-09-12', $this->states['actual']['dateTo']);
    }

    public static function nonTeamStates(): array
    {
        return [
            'cleared default' => [null, true, true],
            'default without membership' => ['A', true, false],
            'deactivated user' => ['A', false, true],
        ];
    }

    #[DataProvider('nonTeamStates')]
    public function testReserveAndDeactivationAreRecordedWithoutChangingCrm(?string $default, bool $active, bool $related): void
    {
        $recorder = $this->recorder(['actual' => ['dateFrom' => '2026-01-01', 'dateTo' => null]]);
        $this->default = $default;
        $this->active = $active;
        $this->related = $related;
        $recorder->recordForUser('user', '2026-09-12');
        $this->assertNull($this->states['new-1']['teamId']);
        $this->assertNull($this->states['new-1']['boardSquadId']);
        $this->assertSame($active, $this->states['new-1']['crmIsActive']);
        $this->assertTrue($this->states['new-1']['isCrmState']);
        $this->assertSame('2026-09-12', $this->states['actual']['dateTo']);
    }
}
