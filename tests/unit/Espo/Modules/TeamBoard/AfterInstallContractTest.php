<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Core\Container;
use Espo\Core\InjectableFactory;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Install\ScheduledJobsInstaller;
use Espo\Modules\TeamBoard\Tools\Shadow\Backfill;
use Espo\Modules\TeamBoard\Tools\Shadow\BackfillReport;
use Espo\Modules\TeamBoard\Tools\Shadow\DisplacementLinkBackfill;
use Espo\Modules\TeamBoard\Tools\Timeline\CrmStateRecorder;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\TestCase;

class AfterInstallContractTest extends TestCase
{
    public function testBackfillAndCrmBaselineAreOneTransaction(): void
    {
        require_once dirname(__DIR__, 5) . '/src/scripts/AfterInstall.php';
        $inside = false;
        $order = [];
        $transaction = $this->createMock(TransactionManager::class);
        $transaction->expects($this->once())->method('run')->willReturnCallback(static function ($work) use (&$inside): void {
            $inside = true;
            try { $work(); } finally { $inside = false; }
        });
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn('user');
        $select = $this->createStub(RDBSelectBuilder::class);
        $select->method('find')->willReturn(new EntityCollection([$user]));
        $repository = $this->createMock(RDBRepository::class);
        $repository->expects($this->once())->method('where')
            ->with(['type' => [User::TYPE_REGULAR, User::TYPE_ADMIN]])->willReturn($select);
        $em = $this->createMock(EntityManager::class);
        $em->method('getTransactionManager')->willReturn($transaction);
        $em->method('getRDBRepositoryByClass')->willReturn($repository);
        $em->expects($this->never())->method('getQueryBuilder');
        $backfill = $this->createMock(Backfill::class);
        $backfill->expects($this->once())->method('run')->willReturnCallback(function () use (&$inside, &$order) {
            $this->assertTrue($inside);
            $order[] = 'backfill';
            return new BackfillReport(1, 1, 1);
        });
        $displacementLinks = $this->createMock(DisplacementLinkBackfill::class);
        $displacementLinks->expects($this->once())->method('run')->willReturnCallback(function () use (&$inside, &$order) {
            $this->assertTrue($inside);
            $order[] = 'displacementLinks';
            return 0;
        });
        $recorder = $this->createMock(CrmStateRecorder::class);
        $recorder->expects($this->once())->method('recordForUser')->with('user')
            ->willReturnCallback(function () use (&$inside, &$order): void {
                $this->assertTrue($inside);
                $order[] = 'baseline';
            });
        $jobs = $this->createMock(ScheduledJobsInstaller::class);
        $jobs->expects($this->once())->method('run')->willReturnCallback(function () use (&$inside, &$order): void {
            $this->assertTrue($inside);
            $order[] = 'jobs';
        });
        $factory = $this->createStub(InjectableFactory::class);
        $factory->method('create')->willReturnCallback(static fn ($class) => [
            Backfill::class => $backfill,
            DisplacementLinkBackfill::class => $displacementLinks,
            CrmStateRecorder::class => $recorder,
            ScheduledJobsInstaller::class => $jobs,
        ][$class]);
        $container = $this->createStub(Container::class);
        $container->method('getByClass')->willReturnCallback(static fn ($class) => [
            EntityManager::class => $em, InjectableFactory::class => $factory,
        ][$class]);

        (new \AfterInstall())->run($container);

        $this->assertSame(['backfill', 'displacementLinks', 'baseline', 'jobs'], $order);
    }
}
