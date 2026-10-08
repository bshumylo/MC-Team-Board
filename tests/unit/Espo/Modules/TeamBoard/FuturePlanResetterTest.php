<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\Modules\TeamBoard\Tools\Timeline\DraftReminder;
use Espo\Modules\TeamBoard\Tools\Timeline\FuturePlanResetter;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\TransactionManager;
use PHPUnit\Framework\TestCase;

class FuturePlanResetterTest extends TestCase
{
    public function testResetsOnlyUnappliedConfirmedIncludingLatePlansInReverseOrder(): void
    {
        $record = function (string $id, string $date, string $status, ?string $applied = null): Entity {
            $entity = $this->createStub(Entity::class);
            $entity->method('getId')->willReturn($id);
            $entity->method('get')->willReturnCallback(static fn ($name) => [
                'dateFrom' => $date, 'status' => $status, 'appliedAt' => $applied,
            ][$name] ?? null);
            return $entity;
        };
        $first = $record('first', '2026-12-10', 'confirmed');
        $last = $record('last', '2026-12-20', 'confirmed');
        $query = $this->createStub(AssignmentQuery::class);
        $query->method('findForMember')->willReturn([
            $record('past', '2026-11-01', 'confirmed', '2026-11-01 00:00:00'),
            $record('today', '2026-12-01', 'confirmed'),
            $record('late', '2026-11-20', 'confirmed'),
            $first, $last, $record('draft', '2026-12-25', 'draft'),
            $record('already-applied', '2026-12-30', 'confirmed', '2026-12-01 00:00:00'),
        ]);
        $member = $this->createStub(Entity::class);
        $member->method('getId')->willReturn('board-person');
        $registry = $this->createStub(Registry::class);
        $registry->method('memberForUser')->willReturn($member);
        $transaction = $this->createStub(TransactionManager::class);
        $transaction->method('run')->willReturnCallback(static fn ($work) => $work());
        $em = $this->createStub(EntityManager::class);
        $em->method('getTransactionManager')->willReturn($transaction);
        $editor = $this->createMock(AssignmentEditor::class);
        $seen = [];
        $editor->expects($this->exactly(4))->method('returnToDraft')
            ->willReturnCallback(static function ($assignment) use (&$seen): void {
                $seen[] = $assignment->getId();
            });

        // O02: every plan returned to Draft is handed to the reminder at once;
        // the reminder itself ignores dates outside the 7-day window.
        $reminder = $this->createMock(DraftReminder::class);
        $notified = [];
        $reminder->expects($this->exactly(4))->method('notifyForAssignment')
            ->willReturnCallback(static function ($assignment) use (&$notified): void {
                $notified[] = $assignment->getId();
            });

        (new FuturePlanResetter($em, $query, $registry, $editor, $reminder))->resetForUser('user');

        $this->assertSame(['last', 'first', 'today', 'late'], $seen);
        $this->assertSame(['last', 'first', 'today', 'late'], $notified);
    }

    public function testAManualCrmChangeAlsoReturnsDisplacementTailsToDraft(): void
    {
        $record = function (string $id, string $date, ?string $displacedBy): Entity {
            $entity = $this->createStub(Entity::class);
            $entity->method('getId')->willReturn($id);
            $entity->method('get')->willReturnCallback(static fn ($name) => [
                'dateFrom' => $date, 'status' => 'confirmed', 'appliedAt' => null,
                'displacedById' => $displacedBy,
            ][$name] ?? null);
            return $entity;
        };
        $query = $this->createStub(AssignmentQuery::class);
        $query->method('findForMember')->willReturn([
            $record('own-plan', '2026-12-10', null),
            $record('someone-elses-tail', '2026-12-20', 'leader-plan'),
            // A pre-upgrade tail has no owner recorded and keeps the old
            // behaviour, so an upgrade never strands a record.
            $record('legacy-tail', '2026-12-15', null),
        ]);
        $member = $this->createStub(Entity::class);
        $member->method('getId')->willReturn('board-person');
        $registry = $this->createStub(Registry::class);
        $registry->method('memberForUser')->willReturn($member);
        $transaction = $this->createStub(TransactionManager::class);
        $transaction->method('run')->willReturnCallback(static fn ($work) => $work());
        $em = $this->createStub(EntityManager::class);
        $em->method('getTransactionManager')->willReturn($transaction);
        $editor = $this->createMock(AssignmentEditor::class);
        $seen = [];
        $editor->expects($this->exactly(3))->method('returnToDraft')
            ->willReturnCallback(static function ($assignment) use (&$seen): void {
                $seen[] = $assignment->getId();
            });

        (new FuturePlanResetter($em, $query, $registry, $editor, $this->createStub(DraftReminder::class)))->resetForUser('user');

        // T23: a tail is a confirmed future transition of this person too.
        $this->assertSame(['someone-elses-tail', 'legacy-tail', 'own-plan'], $seen);
    }

    public function testDeactivationAlsoReturnsDisplacementTailsToDraft(): void
    {
        $record = function (string $id, string $date, ?string $displacedBy): Entity {
            $entity = $this->createStub(Entity::class);
            $entity->method('getId')->willReturn($id);
            $entity->method('get')->willReturnCallback(static fn ($name) => [
                'dateFrom' => $date, 'status' => 'confirmed', 'appliedAt' => null,
                'displacedById' => $displacedBy,
            ][$name] ?? null);
            return $entity;
        };
        $query = $this->createStub(AssignmentQuery::class);
        $query->method('findForMember')->willReturn([
            $record('own-plan', '2026-12-10', null),
            $record('someone-elses-tail', '2026-12-20', 'leader-plan'),
        ]);
        $member = $this->createStub(Entity::class);
        $member->method('getId')->willReturn('board-person');
        $registry = $this->createStub(Registry::class);
        $registry->method('memberForUser')->willReturn($member);
        $transaction = $this->createStub(TransactionManager::class);
        $transaction->method('run')->willReturnCallback(static fn ($work) => $work());
        $em = $this->createStub(EntityManager::class);
        $em->method('getTransactionManager')->willReturn($transaction);
        $editor = $this->createMock(AssignmentEditor::class);
        $seen = [];
        $editor->expects($this->exactly(2))->method('returnToDraft')
            ->willReturnCallback(static function ($assignment) use (&$seen): void {
                $seen[] = $assignment->getId();
            });

        (new FuturePlanResetter($em, $query, $registry, $editor, $this->createStub(DraftReminder::class)))->resetForUser('user');

        $this->assertSame(['someone-elses-tail', 'own-plan'], $seen);
    }

    public function testResettingAChainTwiceRestoresTheActualPeriodWithoutLosingPlans(): void
    {
        $states = [
            'actual' => ['status' => 'confirmed', 'dateFrom' => '2026-11-01', 'dateTo' => '2026-12-10',
                'appliedAt' => '2026-11-01 00:00:00'],
            'first' => ['status' => 'confirmed', 'dateFrom' => '2026-12-10', 'dateTo' => '2026-12-20',
                'supersedesId' => 'actual', 'supersededDateTo' => null],
            'last' => ['status' => 'confirmed', 'dateFrom' => '2026-12-20', 'dateTo' => null,
                'supersedesId' => 'first', 'supersededDateTo' => null],
        ];
        $entities = [];
        foreach (array_keys($states) as $id) {
            $entity = $this->createStub(Entity::class);
            $entity->method('getId')->willReturn($id);
            $entity->method('get')->willReturnCallback(static function ($name) use (&$states, $id) {
                return $states[$id][$name] ?? null;
            });
            $entity->method('set')->willReturnCallback(static function ($name, $value = null) use (&$states, $id, $entity) {
                $states[$id] = array_replace($states[$id], is_array($name) ? $name : [$name => $value]);
                return $entity;
            });
            $entities[$id] = $entity;
        }
        $query = $this->createStub(AssignmentQuery::class);
        $query->method('findForMember')->willReturn(array_values($entities));
        $member = $this->createStub(Entity::class);
        $member->method('getId')->willReturn('person');
        $registry = $this->createStub(Registry::class);
        $registry->method('memberForUser')->willReturn($member);
        $transaction = $this->createStub(TransactionManager::class);
        $transaction->method('run')->willReturnCallback(static fn ($work) => $work());
        $em = $this->createMock(EntityManager::class);
        $em->method('getTransactionManager')->willReturn($transaction);
        $em->method('getEntityById')->willReturnCallback(static fn ($type, $id) => $entities[$id]);
        $em->expects($this->exactly(4))->method('saveEntity');
        $resetter = new FuturePlanResetter($em, $query, $registry, new AssignmentEditor($em, $query, $registry), $this->createStub(DraftReminder::class));

        $resetter->resetForUser('user');
        $resetter->resetForUser('user');

        $this->assertNull($states['actual']['dateTo']);
        $this->assertSame('confirmed', $states['actual']['status']);
        $this->assertSame('2026-11-01 00:00:00', $states['actual']['appliedAt']);
        foreach (['first', 'last'] as $id) {
            $this->assertSame('draft', $states[$id]['status']);
            $this->assertNull($states[$id]['supersedesId']);
            $this->assertNull($states[$id]['dateTo']);
        }
        $this->assertSame('2026-12-10', $states['first']['dateFrom']);
        $this->assertSame('2026-12-20', $states['last']['dateFrom']);
    }
}
