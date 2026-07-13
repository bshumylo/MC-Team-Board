<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Modules\TeamBoard\Tools\Board\Position;
use PHPUnit\Framework\TestCase;

class PositionTest extends TestCase
{
    public function testDefaultListContainsAllPositions(): void
    {
        $this->assertSame(
            ['Supervisor', 'Leader', 'Vice Leader', 'Member'],
            Position::DEFAULT_LIST
        );
    }

    public function testListForFallsBackToDefault(): void
    {
        $this->assertSame(Position::DEFAULT_LIST, Position::listFor(null));
        $this->assertSame(Position::DEFAULT_LIST, Position::listFor([]));
        $this->assertSame(Position::DEFAULT_LIST, Position::listFor(['', null]));
    }

    public function testListForUsesTeamList(): void
    {
        $this->assertSame(
            ['Chief', 'Deputy', 'Agent'],
            Position::listFor(['Chief', 'Deputy', 'Agent'])
        );
    }

    public function testTopSkipsSupervisor(): void
    {
        $this->assertSame(
            Position::LEADER,
            Position::topOf(Position::DEFAULT_LIST)
        );

        $this->assertSame('Chief', Position::topOf(['Supervisor', 'Chief', 'Agent']));
        $this->assertSame('Chief', Position::topOf(['Chief', 'Agent']));
        $this->assertNull(Position::topOf(['Supervisor']));
        $this->assertNull(Position::topOf([]));
    }

    public function testBottom(): void
    {
        $this->assertSame(
            Position::MEMBER,
            Position::bottomOf(Position::DEFAULT_LIST)
        );

        $this->assertSame('Agent', Position::bottomOf(['Chief', 'Agent', 'Supervisor']));
        $this->assertSame('Supervisor', Position::bottomOf(['Supervisor']));
        $this->assertSame(Position::MEMBER, Position::bottomOf([]));
    }
}
