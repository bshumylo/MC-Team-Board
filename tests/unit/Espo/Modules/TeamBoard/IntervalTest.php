<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Modules\TeamBoard\Tools\Timeline\Interval;
use PHPUnit\Framework\TestCase;

class IntervalTest extends TestCase
{
    public function testCoversIsInclusiveOnTheStartAndExclusiveOnTheEnd(): void
    {
        $interval = Interval::of('2026-03-01', '2026-04-01');

        $this->assertFalse($interval->covers('2026-02-28'));
        $this->assertTrue($interval->covers('2026-03-01'));
        $this->assertTrue($interval->covers('2026-03-31'));
        $this->assertFalse($interval->covers('2026-04-01'));
    }

    public function testOpenEndedIntervalCoversEverythingAfterItsStart(): void
    {
        $interval = Interval::of('2026-03-01', null);

        $this->assertFalse($interval->covers('2026-02-28'));
        $this->assertTrue($interval->covers('2026-03-01'));
        $this->assertTrue($interval->covers('2099-01-01'));
    }

    public function testSameDayHandoverDoesNotOverlap(): void
    {
        $leaving = Interval::of('2026-01-01', '2026-03-01');
        $joining = Interval::of('2026-03-01', null);

        $this->assertFalse($leaving->overlaps($joining));
        $this->assertFalse($joining->overlaps($leaving));
    }

    public function testOverlapIsDetectedInBothDirections(): void
    {
        $a = Interval::of('2026-01-01', '2026-04-01');
        $b = Interval::of('2026-03-01', '2026-06-01');

        $this->assertTrue($a->overlaps($b));
        $this->assertTrue($b->overlaps($a));
    }

    public function testTwoOpenEndedIntervalsAlwaysOverlap(): void
    {
        $this->assertTrue(
            Interval::of('2026-01-01', null)->overlaps(Interval::of('2030-01-01', null))
        );
    }

    public function testZeroLengthAndInvertedIntervalsAreInvalid(): void
    {
        $this->assertFalse(Interval::of('2026-03-01', '2026-03-01')->isValid());
        $this->assertFalse(Interval::of('2026-03-02', '2026-03-01')->isValid());
        $this->assertTrue(Interval::of('2026-03-01', '2026-03-02')->isValid());
        $this->assertTrue(Interval::of('2026-03-01', null)->isValid());
    }

    public function testZoneIsClassifiedByThePeriodNotByItsStartDate(): void
    {
        $today = '2026-09-01';

        // A leader in place since May: started long ago, still live.
        $this->assertSame(
            Interval::ZONE_LIVE,
            Interval::of('2026-05-01', null)->zoneAt($today)
        );

        // Ends today — dateTo is exclusive, so today is already outside it.
        $this->assertSame(
            Interval::ZONE_ENDED,
            Interval::of('2026-05-01', '2026-09-01')->zoneAt($today)
        );

        $this->assertSame(
            Interval::ZONE_LIVE,
            Interval::of('2026-05-01', '2026-09-02')->zoneAt($today)
        );

        $this->assertSame(
            Interval::ZONE_PLANNED,
            Interval::of('2026-10-01', null)->zoneAt($today)
        );

        // Starts today: live from this morning, not planned.
        $this->assertSame(
            Interval::ZONE_LIVE,
            Interval::of('2026-09-01', null)->zoneAt($today)
        );
    }

    public function testWithDateToReturnsANewInterval(): void
    {
        $original = Interval::of('2026-03-01', null);
        $closed = $original->withDateTo('2026-10-01');

        $this->assertNull($original->getDateTo());
        $this->assertSame('2026-10-01', $closed->getDateTo());
        $this->assertSame('2026-03-01', $closed->getDateFrom());
    }
}
