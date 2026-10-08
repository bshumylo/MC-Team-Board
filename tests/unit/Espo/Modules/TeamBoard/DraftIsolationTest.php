<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\TestCase;

class DraftIsolationTest extends TestCase
{
    private function record(string $id, array $data, bool $readOnly = false): Entity
    {
        $entity = $readOnly ? $this->createMock(Entity::class) : $this->createStub(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(static fn ($name) => $data[$name] ?? null);
        if ($readOnly) {
            $entity->expects($this->never())->method('set');
        } else {
            $entity->method('set')->willReturnSelf();
        }
        return $entity;
    }

    private function editor(Entity $assignment, array $existing): AssignmentEditor
    {
        $transaction = $this->createStub(TransactionManager::class);
        $transaction->method('run')->willReturnCallback(static fn ($work) => $work());
        $em = $this->createMock(EntityManager::class);
        $em->method('getTransactionManager')->willReturn($transaction);
        $em->method('getNewEntity')->willReturn($assignment);
        $em->expects($this->once())->method('saveEntity')->with($this->identicalTo($assignment));
        $em->expects($this->never())->method('getRDBRepository');
        $query = $this->createStub(AssignmentQuery::class);
        $query->method('findForMember')->willReturn($existing);
        return new AssignmentEditor($em, $query, $this->createStub(Registry::class));
    }

    private function predecessor(string $status = Status::CONFIRMED): Entity
    {
        return $this->record('previous', ['boardSquadId' => 'squad-a', 'teamId' => 'team-a',
            'position' => 'Member', 'dateFrom' => '2026-01-01', 'dateTo' => null,
            'status' => $status], true);
    }

    public function testCreatingDraftDoesNotCloseConfirmedPredecessor(): void
    {
        $assignment = $this->record('new', []);
        $editor = $this->editor($assignment, [$this->predecessor()]);
        $editor->createShadow('person', 'squad-b', 'user', 'team-b', 'Member',
            '2026-12-01', null, Status::DRAFT, null, ['Supervisor', 'Leader', 'Member']);
    }

    public function testEditingDraftDoesNotCloseConfirmedPredecessor(): void
    {
        $assignment = $this->record('draft', ['boardMemberId' => 'person', 'memberId' => 'user', 'status' => Status::DRAFT]);
        $editor = $this->editor($assignment, [$this->predecessor()]);
        $editor->updateShadow($assignment, 'Member', 'squad-b', 'team-b',
            '2026-12-01', null, Status::DRAFT, null);
    }

    public function testDraftLeaderDoesNotDisplaceAnyIncumbent(): void
    {
        $assignment = $this->record('draft', []);
        $editor = $this->editor($assignment, []);
        $editor->createShadow('person', 'squad-b', 'user', 'team-b', 'Leader',
            '2026-12-01', null, Status::DRAFT, null, ['Supervisor', 'Leader', 'Member']);
    }

    public function testConfirmedMoveDoesNotTreatAnotherDraftAsItsPredecessor(): void
    {
        $assignment = $this->record('new', []);
        $editor = $this->editor($assignment, [$this->predecessor(Status::DRAFT)]);
        $editor->createShadow('person', 'squad-b', 'user', 'team-b', 'Member',
            '2026-12-01', null, Status::CONFIRMED, null, ['Supervisor', 'Leader', 'Member']);
    }
}
