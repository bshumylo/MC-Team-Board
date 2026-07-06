<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Modules\TeamBoard\Tools\Board\Position;
use PHPUnit\Framework\TestCase;

class PositionTest extends TestCase
{
    public function testListContainsAllPositions(): void
    {
        $this->assertSame(
            ['Supervisor', 'Leader', 'Vice Leader', 'Member'],
            Position::LIST
        );
    }

    public function testExclusivePositions(): void
    {
        $this->assertContains(Position::LEADER, Position::EXCLUSIVE_LIST);
        $this->assertContains(Position::VICE_LEADER, Position::EXCLUSIVE_LIST);
        $this->assertNotContains(Position::SUPERVISOR, Position::EXCLUSIVE_LIST);
        $this->assertNotContains(Position::MEMBER, Position::EXCLUSIVE_LIST);
    }
}
