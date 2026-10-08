<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Modules\TeamBoard\Tools\Timeline\Interval;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\Modules\TeamBoard\Tools\Timeline\Zone;
use PHPUnit\Framework\TestCase;

class ZoneTest extends TestCase
{
    private const TODAY = '2026-09-01';

    public function testOrganiserMayPlanAFuturePeriod(): void
    {
        $this->assertTrue(
            Zone::mayCreate(Interval::of('2026-10-01', null), self::TODAY, false)
        );
    }

    public function testOrganiserMayNotCreateAPeriodThatStartsTodayOrEarlier(): void
    {
        $this->assertFalse(
            Zone::mayCreate(Interval::of('2026-09-01', null), self::TODAY, false)
        );

        $this->assertTrue(
            Zone::mayCreate(Interval::of('2026-09-01', null), self::TODAY, true)
        );
    }

    public function testNobodyMayCreateAnAlreadyEndedPeriodThroughTheBoard(): void
    {
        $ended = Interval::of('2026-01-01', '2026-02-01');

        $this->assertFalse(Zone::mayCreate($ended, self::TODAY, false));
        $this->assertFalse(Zone::mayCreate($ended, self::TODAY, true));
    }

    public function testOrganiserMayPlanAnExitOnALivePeriod(): void
    {
        $live = Interval::of('2026-05-01', null);

        $this->assertTrue(
            Zone::mayChange($live, $live->withDateTo('2026-10-01'), false, self::TODAY, false)
        );
    }

    public function testOrganiserMayNotBackdateTheEndOfALivePeriod(): void
    {
        $live = Interval::of('2026-05-01', null);

        $this->assertFalse(
            Zone::mayChange($live, $live->withDateTo('2026-08-01'), false, self::TODAY, false)
        );

        $this->assertFalse(
            Zone::mayChange($live, $live->withDateTo('2026-09-01'), false, self::TODAY, false)
        );

        $this->assertTrue(
            Zone::mayChange($live, $live->withDateTo('2026-09-01'), false, self::TODAY, true)
        );
    }

    public function testOrganiserMayNotChangeTeamOrPositionOfALivePeriod(): void
    {
        $live = Interval::of('2026-05-01', null);

        $this->assertFalse(
            Zone::mayChange($live, $live, true, self::TODAY, false)
        );

        $this->assertTrue(
            Zone::mayChange($live, $live, true, self::TODAY, true)
        );
    }

    public function testOrganiserMayNotChangeTheStartOfALivePeriod(): void
    {
        $live = Interval::of('2026-05-01', null);

        $this->assertFalse(
            Zone::mayChange($live, Interval::of('2026-06-01', null), false, self::TODAY, false)
        );
    }

    public function testALeaderInPlaceSinceMayIsStillRotatable(): void
    {
        $live = Interval::of('2026-05-01', null);

        $this->assertSame(Interval::ZONE_LIVE, $live->zoneAt(self::TODAY));
        $this->assertTrue(
            Zone::mayChange($live, $live->withDateTo('2026-10-01'), false, self::TODAY, false)
        );
    }

    public function testEndedPeriodsAreReadOnlyForEveryone(): void
    {
        $ended = Interval::of('2026-01-01', '2026-02-01');

        $this->assertFalse(
            Zone::mayChange($ended, $ended->withDateTo('2026-03-01'), false, self::TODAY, false)
        );

        $this->assertFalse(
            Zone::mayChange($ended, $ended->withDateTo('2026-03-01'), false, self::TODAY, true)
        );
    }

    public function testPlannedPeriodsAreFullyEditableByOrganisers(): void
    {
        $planned = Interval::of('2026-10-01', '2026-12-01');

        $this->assertTrue(
            Zone::mayChange($planned, Interval::of('2026-11-01', null), true, self::TODAY, false)
        );
    }

    public function testOnlyUnappliedDraftsMayBeDeleted(): void
    {
        $planned = Interval::of('2026-10-01', null);

        $this->assertTrue(
            Zone::mayDelete($planned, Status::DRAFT, null, self::TODAY, false)
        );

        $this->assertFalse(
            Zone::mayDelete($planned, Status::CONFIRMED, null, self::TODAY, false)
        );

        $this->assertFalse(
            Zone::mayDelete($planned, Status::DRAFT, '2026-08-01 03:00:00', self::TODAY, true)
        );

        $this->assertFalse(
            Zone::mayDelete(Interval::of('2026-05-01', null), Status::DRAFT, null, self::TODAY, false)
        );
    }

    public function testOrganiserCannotMoveAFuturePlanIntoTodayOrThePast(): void
    {
        $planned = Interval::of('2026-10-01', null);
        foreach (['2026-09-01', '2026-08-01'] as $start) {
            $this->assertFalse(Zone::mayChange(
                $planned, Interval::of($start, null), false, self::TODAY, false
            ));
        }
        $this->assertTrue(Zone::mayChange(
            $planned, Interval::of(self::TODAY, null), false, self::TODAY, true
        ));
    }

    public function testEndedIntervalsMayNotBeDeletedEvenByAdministrators(): void
    {
        $this->assertFalse(
            Zone::mayDelete(
                Interval::of('2026-01-01', '2026-02-01'),
                Status::DRAFT,
                null,
                self::TODAY,
                true
            )
        );
    }
}
