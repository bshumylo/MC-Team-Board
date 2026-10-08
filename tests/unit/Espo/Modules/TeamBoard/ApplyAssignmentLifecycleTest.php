<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Jobs\ApplyDueAssignments;
use Espo\Modules\TeamBoard\Tools\Board\Service;
use Espo\Modules\TeamBoard\Tools\Dashboard\Reconciler;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class ApplyAssignmentLifecycleTest extends TestCase
{
    public static function applications(): array
    {
        return [
            'active user' => [true, false],
            'inactive user' => [false, false],
            'failed completion marker' => [true, true],
        ];
    }

    #[DataProvider('applications')]
    public function testApplyUsesCurrentDefaultAndWritesMarkerInTheSameTransaction(bool $active, bool $fail): void
    {
        $user = $this->createStub(User::class);
        $user->method('isActive')->willReturn($active);
        $user->method('get')->willReturnCallback(static fn ($name) => $name === 'defaultTeamId' ? 'A' : null);
        $team = $this->createStub(Team::class);
        $userRepo = $this->createStub(RDBRepository::class);
        $userRepo->method('getById')->willReturn($user);
        $userSelect = $this->createStub(RDBSelectBuilder::class);
        $userSelect->method('where')->willReturnSelf();
        $userSelect->method('findOne')->willReturn($user);
        $userRepo->method('forUpdate')->willReturn($userSelect);
        $teamRepo = $this->createStub(RDBRepository::class);
        $teamRepo->method('getById')->willReturn($team);
        $assignment = $this->createMock(Entity::class);
        $assignment->method('getId')->willReturn('plan');
        $assignment->method('get')->willReturnCallback(static fn ($name) => [
            'memberId' => 'user', 'teamId' => 'G', 'dateFrom' => '2026-12-01',
            'position' => 'Member', 'status' => 'confirmed',
        ][$name] ?? null);
        $assignment->expects($active ? $this->once() : $this->never())->method('set')
            ->with('appliedAt', $this->isString());
        $inTransaction = false;
        $transaction = $this->createMock(TransactionManager::class);
        $transaction->expects($active ? $this->once() : $this->never())->method('run')
            ->willReturnCallback(static function ($work) use (&$inTransaction): void {
                $inTransaction = true;
                try {
                    $work();
                } finally {
                    $inTransaction = false;
                }
            });
        $em = $this->createMock(EntityManager::class);
        $em->method('getTransactionManager')->willReturn($transaction);
        $em->method('getRDBRepositoryByClass')->willReturnCallback(static fn ($class) =>
            $class === User::class ? $userRepo : $teamRepo);
        $em->expects($active ? $this->once() : $this->never())->method('saveEntity')
            ->willReturnCallback(function ($entity) use ($assignment, &$inTransaction, $fail): void {
                $this->assertSame($assignment, $entity);
                $this->assertTrue($inTransaction, 'Move and its completion marker must commit together.');
                if ($fail) {
                    throw new RuntimeException('simulated marker failure');
                }
            });
        $service = $this->createMock(Service::class);
        $service->expects($active ? $this->once() : $this->never())->method('move')
            ->with('user', 'G', 'A', 'Member', true)
            ->willReturnCallback(function () use (&$inTransaction): object {
                $this->assertTrue($inTransaction);
                return (object) [];
            });
        $reconciler = $this->createMock(Reconciler::class);
        $reconciler->expects($active && !$fail ? $this->once() : $this->never())->method('reconcile')->with('user');
        $log = $this->createMock(Log::class);
        $log->expects($active ? $this->never() : $this->once())->method('warning');
        $log->expects($fail ? $this->once() : $this->never())->method('error');
        $query = $this->createMock(AssignmentQuery::class);
        $query->expects($active ? $this->once() : $this->never())->method('lockForUpdate')->with('plan')
            ->willReturnCallback(function () use ($assignment, &$inTransaction) {
                $this->assertTrue($inTransaction);
                return $assignment;
            });
        $job = new ApplyDueAssignments($query, $em,
            $this->createStub(InjectableFactory::class), $reconciler, $log,
            $this->createStub(\Espo\Modules\TeamBoard\Tools\Timeline\CrmStateRecorder::class),
            $this->createStub(\Espo\Modules\TeamBoard\Tools\Timeline\DraftReminder::class),
            $this->createStub(\Espo\Core\Utils\DateTime::class),
            $this->createStub(\Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor::class));

        (new ReflectionMethod($job, 'apply'))->invoke($job, $service, $assignment, '2026-12-01');
    }
}
