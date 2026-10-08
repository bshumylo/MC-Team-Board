<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Modules\TeamBoard\Tools\Dashboard\ValueRule;
use PHPUnit\Framework\TestCase;

class ValueRuleTest extends TestCase
{
    /**
     * @param string[] $validIds
     * @return callable(string): bool
     */
    private function validator(array $validIds): callable
    {
        return fn (string $id) => in_array($id, $validIds, true);
    }

    public function testValidListIsNotChanged(): void
    {
        $this->assertNull(
            ValueRule::apply(['t1', 't2'], $this->validator(['t1', 't2', 't3']), ['t9'])
        );
    }

    public function testEmptyListIsNotChanged(): void
    {
        $this->assertNull(ValueRule::apply([], $this->validator([]), ['t1']));
    }

    public function testPartiallyStaleListIsResetNotTrimmed(): void
    {
        $this->assertSame(
            ['t3'],
            ValueRule::apply(['t1', 't2'], $this->validator(['t2', 't3']), ['t3']),
            'What survives a rotation is a stale slice, so it is replaced, not kept.'
        );
    }

    public function testFullyStaleListIsReplacedByFallback(): void
    {
        $this->assertSame(
            ['t2', 't3'],
            ValueRule::apply(['t1'], $this->validator([]), ['t2', 't3'])
        );
    }

    public function testEmptyFallbackClearsTheList(): void
    {
        $this->assertSame([], ValueRule::apply(['u1'], $this->validator([]), []));
    }

    public function testNonStringEntryIsTreatedAsStale(): void
    {
        $this->assertSame(
            ['t1'],
            ValueRule::apply([null, 5], $this->validator(['t1']), ['t1'])
        );
    }

    public function testKeepValidLeavesAFullyValidListUnchanged(): void
    {
        $this->assertNull(ValueRule::keepValid(['u1', 'u2'], $this->validator(['u1', 'u2'])));
        $this->assertNull(ValueRule::keepValid([], $this->validator([])));
    }

    public function testKeepValidDropsOnlyForbiddenIds(): void
    {
        $this->assertSame(
            ['u1', 'u3'],
            ValueRule::keepValid(['u1', 'u2', 'u3', null], $this->validator(['u1', 'u3']))
        );
    }

    public function testKeepValidClearsAWhollyInvalidList(): void
    {
        $this->assertSame([], ValueRule::keepValid(['u1'], $this->validator([])));
    }

    public function testFallbackKeysAreReindexed(): void
    {
        $this->assertSame(
            ['t2', 't3'],
            ValueRule::apply(['t1'], $this->validator([]), [3 => 't2', 7 => 't3'])
        );
    }
}
