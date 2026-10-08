<?php

namespace Espo\Modules\TeamBoard\Tools\Timeline;

/**
 * D13 — server-side permission rules for assignment periods.
 *
 * The caller has already passed its ordinary TeamBoard and Team ACL checks.
 * This class only decides whether a requested change is valid in the period's
 * time zone.
 */
final class Zone
{
    public static function mayCreate(Interval $interval, string $today, bool $isAdmin): bool
    {
        $zone = $interval->zoneAt($today);

        if ($zone === Interval::ZONE_ENDED) {
            return false;
        }

        return $zone === Interval::ZONE_PLANNED || $isAdmin;
    }

    /**
     * @param bool $teamOrPositionChanged Whether the supplied team or position
     *        differs from the stored assignment.
     */
    public static function mayChange(
        Interval $current,
        Interval $next,
        bool $teamOrPositionChanged,
        string $today,
        bool $isAdmin
    ): bool {
        $zone = $current->zoneAt($today);

        if ($zone === Interval::ZONE_ENDED) {
            return false;
        }

        if ($zone === Interval::ZONE_PLANNED && !$isAdmin) {
            return $next->zoneAt($today) === Interval::ZONE_PLANNED;
        }

        if ($isAdmin) {
            return true;
        }

        if ($teamOrPositionChanged || $next->getDateFrom() !== $current->getDateFrom()) {
            return false;
        }

        $nextDateTo = $next->getDateTo();

        if ($nextDateTo === null) {
            return $current->getDateTo() === null;
        }

        // A non-admin may only plan a future exit from a live period.
        return $nextDateTo > $today;
    }

    /**
     * Only an unapplied draft can be deleted. Ended periods are historical
     * records and stay read-only for every user, including administrators.
     */
    public static function mayDelete(
        Interval $current,
        string $status,
        ?string $appliedAt,
        string $today,
        bool $isAdmin
    ): bool {
        if ($status !== Status::DRAFT || $appliedAt !== null) {
            return false;
        }

        $zone = $current->zoneAt($today);

        if ($zone === Interval::ZONE_ENDED) {
            return false;
        }

        return $zone === Interval::ZONE_PLANNED || $isAdmin;
    }
}
