<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PredecessorRestorationTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'unchanged closure on delete' => ['delete', '2026-12-01', null, true],
            'unchanged closure on revert' => ['revert', '2026-12-01', null, true],
            'later edit on delete' => ['delete', '2026-11-15', null, false],
            'later edit on revert' => ['revert', '2026-11-15', null, false],
            'applied transition on revert' => ['revert', '2026-12-01', '2026-12-01 00:01:00', false],
            'unchanged closure on CRM reset' => ['reset', '2026-12-01', null, true],
            'later edit on CRM reset' => ['reset', '2026-11-15', null, false],
            'applied transition on CRM reset' => ['reset', '2026-12-01', '2026-12-01 00:01:00', false],
        ];
    }

    #[DataProvider('cases')]
    public function testRestoresOnlyItsOwnUnappliedClosure(
        string $action,
        string $currentEnd,
        ?string $appliedAt,
        bool $restore
    ): void {
        $assignment = $this->createStub(Entity::class);
        $assignment->method('getId')->willReturn('plan');
        $assignment->method('get')->willReturnCallback(static fn ($name) => [
            'boardMemberId' => 'person', 'memberId' => 'user',
            'supersedesId' => 'previous', 'supersededDateTo' => null,
            'dateFrom' => '2026-12-01', 'appliedAt' => $appliedAt,
            'status' => Status::CONFIRMED,
        ][$name] ?? null);
        $assignment->method('set')->willReturnSelf();
        $previous = $this->createMock(Entity::class);
        $previous->method('get')->willReturnCallback(static fn ($name) => [
            'dateTo' => $currentEnd, 'status' => Status::CONFIRMED,
        ][$name] ?? null);
        $previous->expects($restore ? $this->once() : $this->never())
            ->method('set')->with('dateTo', null)->willReturnSelf();
        $transaction = $this->createStub(TransactionManager::class);
        $transaction->method('run')->willReturnCallback(static fn ($work) => $work());
        $em = $this->createMock(EntityManager::class);
        $em->method('getTransactionManager')->willReturn($transaction);
        $em->method('getEntityById')->willReturn($previous);
        $saved = [];
        $changesPlan = $action === 'revert' || ($action === 'reset' && $appliedAt === null);
        $em->expects($this->exactly(($restore ? 1 : 0) + ($changesPlan ? 1 : 0)))
            ->method('saveEntity')->willReturnCallback(static function ($entity) use (&$saved): void {
                $saved[] = $entity;
            });
        $em->expects($action === 'delete' ? $this->once() : $this->never())
            ->method('removeEntity')->with($assignment);
        $editor = new AssignmentEditor($em, $this->createStub(AssignmentQuery::class), $this->createStub(Registry::class));

        if ($action === 'delete') {
            $editor->delete($assignment);
        } elseif ($action === 'reset') {
            $editor->returnToDraft($assignment);
        } else {
            $editor->updateShadow($assignment, 'Member', 'squad', 'team', '2026-12-01', null, Status::DRAFT, null);
        }

        $this->assertSame($restore, in_array($previous, $saved, true));
    }
}
