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

    public function testPositionsOneToThreeAreExclusive(): void
    {
        // T08 (changed 2026-09-25): positions 1-3 of the list, except the
        // last list position, are held by one person at a time. The first
        // position is exclusive whatever its name, Supervisor included.
        $this->assertTrue(Position::isExclusive(Position::DEFAULT_LIST, Position::SUPERVISOR));
        $this->assertTrue(Position::isExclusive(Position::DEFAULT_LIST, Position::LEADER));
        $this->assertTrue(Position::isExclusive(Position::DEFAULT_LIST, Position::VICE_LEADER));

        // The last position is the shared ordinary rank.
        $this->assertFalse(Position::isExclusive(Position::DEFAULT_LIST, Position::MEMBER));
    }

    public function testExclusivityFollowsATeamsOwnPositionList(): void
    {
        $list = ['Chief', 'Deputy', 'Aide', 'Senior', 'Agent'];

        $this->assertTrue(Position::isExclusive($list, 'Chief'));
        $this->assertTrue(Position::isExclusive($list, 'Deputy'));
        $this->assertTrue(Position::isExclusive($list, 'Aide'));

        // Positions from the 4th on are equal groups without a holder limit.
        $this->assertFalse(Position::isExclusive($list, 'Senior'));
        $this->assertFalse(Position::isExclusive($list, 'Agent'));

        // An empty team list falls back to the default vocabulary.
        $this->assertTrue(Position::isExclusive([], Position::VICE_LEADER));
        $this->assertTrue(Position::isExclusive([], Position::SUPERVISOR));

        // A position the team does not use displaces nobody.
        $this->assertFalse(Position::isExclusive($list, Position::LEADER));
        $this->assertFalse(Position::isExclusive($list, ''));
    }

    public function testTheLastPositionIsNeverExclusive(): void
    {
        // 2 positions (Captain, Members): Captain is exclusive, Members shared.
        $this->assertTrue(Position::isExclusive(['Captain', 'Members'], 'Captain'));
        $this->assertFalse(Position::isExclusive(['Captain', 'Members'], 'Members'));

        // 3 positions: the 3rd is the last one, so it stays shared.
        $three = ['Supervisor', 'Leader', 'Member'];
        $this->assertTrue(Position::isExclusive($three, 'Supervisor'));
        $this->assertTrue(Position::isExclusive($three, 'Leader'));
        $this->assertFalse(Position::isExclusive($three, 'Member'));
    }

    public function testASingleRankTeamHasNoExclusivePosition(): void
    {
        $this->assertFalse(Position::isExclusive(['Agent'], 'Agent'));
        $this->assertFalse(Position::isExclusive(['Supervisor'], 'Supervisor'));
    }

    public function testBottom(): void
    {
        $this->assertSame(
            Position::MEMBER,
            Position::bottomOf(Position::DEFAULT_LIST)
        );

        // The bottom follows list order, not names (T08/U16): the last
        // position is the ordinary rank even when it is named Supervisor.
        $this->assertSame('Supervisor', Position::bottomOf(['Chief', 'Agent', 'Supervisor']));
        $this->assertSame('Supervisor', Position::bottomOf(['Boss', 'Supervisor']));
        $this->assertSame('Supervisor', Position::bottomOf(['Supervisor']));
        $this->assertSame(Position::MEMBER, Position::bottomOf([]));
    }
}
