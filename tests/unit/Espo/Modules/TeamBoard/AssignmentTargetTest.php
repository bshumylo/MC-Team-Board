<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class AssignmentTargetTest extends TestCase
{
    private function editor(bool $writes): AssignmentEditor
    {
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->never())->method('getNewEntity');
        if ($writes) {
            $transactions = $this->createMock(\Espo\ORM\TransactionManager::class);
            $transactions->expects($this->once())->method('run');
            $em->expects($this->once())->method('getTransactionManager')->willReturn($transactions);
        } else {
            $em->expects($this->never())->method('getTransactionManager');
        }
        return new AssignmentEditor($em, $this->createStub(AssignmentQuery::class), $this->createStub(Registry::class));
    }

    public function testAssignmentWithoutAnyTeamIsRejected(): void
    {
        $editor = $this->editor(false);
        $this->expectException(BadRequest::class);
        $editor->createShadow('board-user', '', 'crm-user', null,
            'Member', '2026-12-01', null, Status::DRAFT, null, ['Member']);
    }

    /** D8-C1, functionality.md section 1: the former ban is lifted. */
    public function testLinkedUserMayBeRetargetedToABoardOnlySquad(): void
    {
        $editor = $this->editor(true);
        $assignment = $this->createStub(Entity::class);
        $assignment->method('get')->willReturnMap([['memberId', 'crm-user']]);
        $editor->updateShadow($assignment, 'Member', 'board-only-squad', null,
            '2026-12-01', null, Status::CONFIRMED, null);
    }
}
