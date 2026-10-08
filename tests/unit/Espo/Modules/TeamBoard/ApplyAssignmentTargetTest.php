<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\TeamBoard\Jobs\ApplyDueAssignments;
use Espo\Modules\TeamBoard\Tools\Board\Service;
use Espo\Modules\TeamBoard\Tools\Dashboard\Reconciler;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ApplyAssignmentTargetTest extends TestCase
{
    public function testLegacyLinkedUserToBoardOnlyPlanCannotMutateCrmOrBeMarkedApplied(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->never())->method('getRDBRepositoryByClass');
        $em->expects($this->never())->method('getTransactionManager');
        $em->expects($this->never())->method('saveEntity');
        $service = $this->createMock(Service::class);
        $service->expects($this->never())->method('move');
        $assignment = $this->createMock(Entity::class);
        $assignment->method('get')->willReturnMap([['memberId', 'linked-user'], ['teamId', null]]);
        $assignment->method('getId')->willReturn('legacy-plan');
        $assignment->expects($this->never())->method('set');
        $log = $this->createMock(Log::class);
        $log->expects($this->once())->method('warning');
        $job = new ApplyDueAssignments($this->createStub(AssignmentQuery::class), $em,
            $this->createStub(InjectableFactory::class), $this->createStub(Reconciler::class),
            $log, $this->createStub(\Espo\Modules\TeamBoard\Tools\Timeline\CrmStateRecorder::class),
            $this->createStub(\Espo\Modules\TeamBoard\Tools\Timeline\DraftReminder::class),
            $this->createStub(\Espo\Core\Utils\DateTime::class),
            $this->createStub(\Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor::class));
        (new ReflectionMethod($job, 'apply'))->invoke($job, $service, $assignment, '2026-12-01');
    }
}
