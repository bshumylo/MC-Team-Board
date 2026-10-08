<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Entities\User;
use Espo\Modules\TeamBoard\Hooks\User\EnsureBoardMember;
use Espo\Modules\TeamBoard\Tools\Shadow\Backfill;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\Modules\TeamBoard\Tools\Timeline\CrmStateRecorder;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class CrmStateLifecycleTest extends TestCase
{
    public static function creations(): array
    {
        return [
            'new regular user' => [true, true, 'regular', true],
            'existing user' => [false, true, 'regular', false],
            'inactive user' => [true, false, 'regular', false],
            'portal user' => [true, true, 'portal', false],
        ];
    }

    #[DataProvider('creations')]
    public function testInitialFactIsRecordedForNewEligibleUser(bool $new, bool $active, string $type, bool $record): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn('user');
        $user->method('isNew')->willReturn($new);
        $user->method('get')->willReturnCallback(static fn ($name) => ['isActive' => $active, 'type' => $type][$name] ?? null);
        $registry = $this->createMock(Registry::class);
        $registry->expects($record ? $this->once() : $this->never())->method('memberForUser')->with('user')
            ->willReturn($this->createStub(Entity::class));
        $recorder = $this->createMock(CrmStateRecorder::class);
        $recorder->expects($record ? $this->once() : $this->never())->method('recordForUser')->with('user');

        (new EnsureBoardMember($registry, $recorder))->afterSave($user, SaveOptions::fromAssoc([]));
    }

    public static function reserves(): array
    {
        return ['factual Reserve' => [true], 'invalid legacy plan' => [false]];
    }

    #[DataProvider('reserves')]
    public function testBackfillAcceptsOnlyFactualUnassignedCrmState(bool $fact): void
    {
        $assignment = $this->createStub(Entity::class);
        $assignment->method('get')->willReturnCallback(static fn ($name) => [
            'memberId' => 'user', 'boardMemberId' => 'person', 'isCrmState' => $fact,
            'appliedAt' => $fact ? '2026-09-12 00:00:00' : null,
        ][$name] ?? null);
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->never())->method('saveEntity');
        $registry = $this->createMock(Registry::class);
        $registry->expects($this->never())->method('squadForTeam');
        if (!$fact) {
            $this->expectException(RuntimeException::class);
        }
        $backfill = new Backfill($em, $registry);
        (new ReflectionMethod($backfill, 'mapAssignment'))->invoke($backfill, $assignment);
    }
}
