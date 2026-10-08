<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Jobs\ApplyDueAssignments;
use Espo\Modules\TeamBoard\Tools\Dashboard\Reconciler;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\Modules\TeamBoard\Tools\Timeline\FuturePlanResetter;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class EndedAssignmentTest extends TestCase
{
    public static function endings(): array
    {
        return [
            'Until without successor' => ['A', true, false, true, true],
            'other current default preserved' => ['B', true, false, true, false],
            'missing membership clears dangling default' => ['A', false, false, false, true],
            'applied successor in same team preserved' => ['A', true, true, false, false],
        ];
    }

    #[DataProvider('endings')]
    public function testEndingIsRecordedOnceWithoutRemovingOtherMemberships(
        string $default, bool $related, bool $successor, bool $unlink, bool $clear
    ): void {
        $record = $this->createStub(Entity::class);
        $record->method('getId')->willReturn('ended-period');
        $record->method('get')->willReturnCallback(static fn ($name) => [
            'memberId' => 'user', 'teamId' => 'A', 'appliedAt' => '2026-11-01 00:00:00',
            'status' => 'confirmed', 'dateTo' => '2026-12-01',
        ][$name] ?? null);
        $recorded = [];
        $record->method('set')->willReturnCallback(static function ($name, $value) use (&$recorded, $record) {
            $recorded[$name] = $value;
            return $record;
        });
        $user = $this->createMock(User::class);
        $user->method('get')->willReturnCallback(static fn ($name) => [
            'defaultTeamId' => $default, 'isActive' => true,
        ][$name] ?? null);
        $user->method('isActive')->willReturn(true);
        $user->expects($clear ? $this->once() : $this->never())->method('set')->with([
            'defaultTeamId' => null, 'defaultTeamName' => null,
        ]);
        $team = $this->createStub(Team::class);
        $userRepo = $this->createStub(RDBRepository::class);
        $userRepo->method('getById')->willReturn($user);
        $userSelect = $this->createStub(RDBSelectBuilder::class);
        $userSelect->method('where')->willReturnSelf();
        $userSelect->method('findOne')->willReturn($user);
        $userRepo->method('forUpdate')->willReturn($userSelect);
        $teamRepo = $this->createStub(RDBRepository::class);
        $teamRepo->method('getById')->willReturn($team);
        $current = $this->createStub(Entity::class);
        $current->method('get')->willReturnCallback(static fn ($name) => [
            'memberId' => 'user', 'teamId' => 'A', 'status' => 'confirmed',
            'appliedAt' => '2026-12-01 00:00:00',
        ][$name] ?? null);
        $query = $this->createStub(AssignmentQuery::class);
        $query->method('findEndedApplied')->willReturn([$record]);
        $query->method('lockForUpdate')->willReturn($record);
        $query->method('findCoveringDate')->willReturn($successor ? [$current] : []);
        $relation = $this->createMock(RDBRelation::class);
        $relation->method('isRelated')->willReturn($related);
        $relation->expects($unlink ? $this->once() : $this->never())->method('unrelate')
            ->with($user, [FuturePlanResetter::SKIP_OPTION => true]);
        $inTransaction = false;
        $transaction = $this->createMock(TransactionManager::class);
        $transaction->expects($this->once())->method('run')->willReturnCallback(
            static function ($work) use (&$inTransaction): void {
                $inTransaction = true;
                $work();
                $inTransaction = false;
            }
        );
        $em = $this->createMock(EntityManager::class);
        $em->method('getTransactionManager')->willReturn($transaction);
        $em->method('getRelation')->willReturn($relation);
        $em->method('getRDBRepositoryByClass')->willReturnCallback(static fn ($class) =>
            $class === User::class ? $userRepo : $teamRepo);
        $saved = [];
        $em->expects($this->exactly($clear ? 2 : 1))->method('saveEntity')->willReturnCallback(
            function ($entity, $options = []) use (&$saved, &$inTransaction, $user): void {
                $this->assertTrue($inTransaction);
                if ($entity === $user) {
                    $this->assertSame([FuturePlanResetter::SKIP_OPTION => true], $options);
                }
                $saved[] = $entity;
            }
        );
        $job = new ApplyDueAssignments($query, $em, $this->createStub(InjectableFactory::class),
            $this->createStub(Reconciler::class), $this->createStub(Log::class),
            $this->createStub(\Espo\Modules\TeamBoard\Tools\Timeline\CrmStateRecorder::class),
            $this->createStub(\Espo\Modules\TeamBoard\Tools\Timeline\DraftReminder::class),
            $this->createStub(\Espo\Core\Utils\DateTime::class),
            $this->createStub(\Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor::class));

        (new ReflectionMethod($job, 'removeExpiredLinkedMemberships'))->invoke($job, '2026-12-01');

        $this->assertTrue(in_array($record, $saved, true), 'The completed Until action must be recorded.');
        $this->assertIsString($recorded['endedAt']);
        $this->assertSame('2026-12-01', $recorded['actualDateTo']);
    }
}
