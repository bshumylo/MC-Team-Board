<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Core\Acl;
use Espo\Core\Record\EntityProvider;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Board\Service;
use Espo\Modules\TeamBoard\Tools\Timeline\FuturePlanResetter;
use Espo\Modules\TeamBoard\Tools\Timeline\Service as TimelineService;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlannedMoveHooksTest extends TestCase
{
    public static function moves(): array
    {
        return ['scheduled transition' => [true], 'manual current move' => [false]];
    }

    #[DataProvider('moves')]
    public function testOnlyScheduledMovePreservesLaterConfirmations(bool $planned): void
    {
        $admin = $this->createStub(User::class);
        $admin->method('isAdmin')->willReturn(true);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn('user');
        $user->method('set')->willReturnSelf();
        $team = $this->createStub(Team::class);
        $team->method('getId')->willReturn('G');
        $team->method('get')->willReturnCallback(static fn ($name) => [
            'positionList' => ['Leader', 'Member'], 'name' => 'Team G',
        ][$name] ?? null);
        $previousTeam = $this->createStub(Team::class);
        $previousTeam->method('getId')->willReturn('A');
        $provider = $this->createStub(EntityProvider::class);
        $provider->method('getByClass')->willReturnCallback(static fn ($class, $id) => [
            'user' => $user, 'G' => $team, 'A' => $previousTeam,
        ][$id]);
        $acl = $this->createStub(Acl::class);
        $acl->method('checkEntityEdit')->willReturn(true);
        $options = $planned ? [FuturePlanResetter::SKIP_OPTION => true] : [];
        $destination = $this->createMock(RDBRelation::class);
        $destination->method('isRelated')->willReturn(false);
        $destination->expects($this->once())->method('relate')->with($user, ['role' => 'Member'], $options);
        $source = $this->createMock(RDBRelation::class);
        $source->expects($this->once())->method('unrelate')->with($user, $options);
        $transaction = $this->createStub(TransactionManager::class);
        $transaction->method('run')->willReturnCallback(static fn ($work) => $work());
        $em = $this->createMock(EntityManager::class);
        $em->method('getTransactionManager')->willReturn($transaction);
        $em->method('getRelation')->willReturnCallback(static fn ($entity) =>
            $entity === $team ? $destination : $source);
        $em->expects($this->once())->method('saveEntity')->with($user, $options);
        $service = $this->createPartialMock(Service::class, ['getData']);
        $service->expects($this->once())->method('getData')->willReturn((object) []);
        // A manual move records the CRM fact itself: a position change alone
        // fires no hooks (T08). The scheduled job records the applied plan.
        $handler = $this->createMock(\Espo\Modules\TeamBoard\Tools\Timeline\CrmChangeHandler::class);
        $handler->expects($planned ? $this->never() : $this->once())->method('handle')->with('user');
        $service->__construct($acl, $admin, $em, $this->createStub(SelectBuilderFactory::class),
            $provider, $this->createStub(TimelineService::class),
            $this->createStub(\Espo\Core\Utils\DateTime::class), $handler,
            $this->createStub(\Espo\Modules\TeamBoard\Tools\Timeline\CrmStateRecorder::class),
            $this->createStub(\Espo\Core\Utils\Language::class));

        $service->move('user', 'G', 'A', 'Member', $planned);
    }
}
