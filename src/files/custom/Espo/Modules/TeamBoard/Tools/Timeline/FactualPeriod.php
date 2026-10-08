<?php

namespace Espo\Modules\TeamBoard\Tools\Timeline;

use Espo\ORM\Entity;

/** Planned boundaries and actual CRM dates can differ when a job runs late. */
class FactualPeriod
{
    public static function isFact(Entity $period): bool
    {
        return is_string($period->get('memberId')) && $period->get('status') === Status::CONFIRMED &&
            $period->get('appliedAt') !== null;
    }

    public static function start(Entity $period): string
    {
        return (string) ($period->get('actualDateFrom') ?? $period->get('dateFrom'));
    }

    public static function end(Entity $period): ?string
    {
        if (is_string($period->get('actualDateTo'))) {
            return $period->get('actualDateTo');
        }
        // For older records, an unprocessed planned Until is not proof that
        // the CRM membership actually ended. AfterInstall reconciles baseline.
        return $period->get('actualDateFrom') !== null ||
            ($period->get('isCrmState') && $period->get('endedAt') === null)
            ? null : $period->get('dateTo');
    }

    public static function covers(Entity $period, string $date): bool
    {
        return Interval::of(self::start($period), self::end($period))->covers($date);
    }

    public static function displayStart(Entity $period): string
    {
        return self::isFact($period) ? self::start($period) : (string) $period->get('dateFrom');
    }

    public static function displayEnd(Entity $period, string $today): ?string
    {
        if (!self::isFact($period)) {
            return $period->get('dateTo');
        }
        $end = self::end($period);
        if ($end !== null) {
            return $end;
        }
        // A future planned Until remains useful in History. Once its date
        // passes, only successful CRM execution establishes the actual end.
        return is_string($period->get('dateTo')) && $period->get('dateTo') > $today
            ? $period->get('dateTo') : null;
    }
}
