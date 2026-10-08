<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Timeline\CrmChangeHandler;
use Espo\Modules\TeamBoard\Tools\Timeline\CrmStateRecorder;
use Espo\Modules\TeamBoard\Tools\Timeline\FuturePlanResetter;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CrmChangeHandlerTest extends TestCase
{
    public static function users(): array
    {
        return ['existing' => [true], 'deleted while processing' => [false]];
    }

    #[DataProvider('users')]
    public function testLocksUserAndResetsPlansBeforeRecordingInOneTransaction(bool $exists): void
    {
        $inside = false;
        $order = [];
        $transaction = $this->createStub(TransactionManager::class);
        $transaction->method('run')->willReturnCallback(static function ($work) use (&$inside): void {
            $inside = true;
            try { $work(); } finally { $inside = false; }
        });
        $select = $this->createMock(RDBSelectBuilder::class);
        $select->expects($this->once())->method('where')->with(['id' => 'user'])->willReturnSelf();
        $select->expects($this->once())->method('findOne')
            ->willReturn($exists ? $this->createStub(User::class) : null);
        $repository = $this->createMock(RDBRepository::class);
        $repository->expects($this->once())->method('forUpdate')->willReturnCallback(function () use (&$inside, &$order, $select) {
            $this->assertTrue($inside);
            $order[] = 'lock';
            return $select;
        });
        $em = $this->createStub(EntityManager::class);
        $em->method('getTransactionManager')->willReturn($transaction);
        $em->method('getRDBRepositoryByClass')->willReturn($repository);
        $resetter = $this->createMock(FuturePlanResetter::class);
        $resetter->expects($exists ? $this->once() : $this->never())->method('resetForUser')->with('user')
            ->willReturnCallback(function () use (&$inside, &$order): void {
                $this->assertTrue($inside);
                $order[] = 'reset';
            });
        $recorder = $this->createMock(CrmStateRecorder::class);
        $recorder->expects($exists ? $this->once() : $this->never())->method('recordForUser')->with('user')
            ->willReturnCallback(function () use (&$inside, &$order): void {
                $this->assertTrue($inside);
                $order[] = 'record';
            });

        (new CrmChangeHandler($em, $resetter, $recorder))->handle('user');

        $this->assertSame($exists ? ['lock', 'reset', 'record'] : ['lock'], $order);
    }
}
