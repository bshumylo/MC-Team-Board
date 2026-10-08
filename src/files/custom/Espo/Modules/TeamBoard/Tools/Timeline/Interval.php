<?php

namespace Espo\Modules\TeamBoard\Tools\Timeline;

/**
 * A membership period, `[dateFrom, dateTo)` (D2).
 *
 * `dateTo` is exclusive — the first day the person is no longer in the team —
 * so a person leaving A and joining B on the same date carries that one date
 * on both records and the two periods do not overlap.
 *
 * `dateTo` may be null, meaning "until the next assignment, or indefinitely".
 *
 * Dates are `Y-m-d` strings. In that format lexicographic order is
 * chronological order, so plain string comparison is correct.
 */
final class Interval
{
    public const ZONE_ENDED = 'ended';
    public const ZONE_LIVE = 'live';
    public const ZONE_PLANNED = 'planned';

    private function __construct(
        private readonly string $dateFrom,
        private readonly ?string $dateTo,
    ) {}

    public static function of(string $dateFrom, ?string $dateTo): self
    {
        return new self($dateFrom, $dateTo);
    }

    public function getDateFrom(): string
    {
        return $this->dateFrom;
    }

    public function getDateTo(): ?string
    {
        return $this->dateTo;
    }

    public function withDateTo(?string $dateTo): self
    {
        return new self($this->dateFrom, $dateTo);
    }

    /**
     * The only hard validation rule (D9): an end date must be after the start.
     * Gaps and overlaps are legal and are warned about, not rejected.
     */
    public function isValid(): bool
    {
        return $this->dateTo === null || $this->dateTo > $this->dateFrom;
    }

    public function covers(string $date): bool
    {
        return $this->dateFrom <= $date &&
            ($this->dateTo === null || $this->dateTo > $date);
    }

    public function overlaps(self $other): bool
    {
        return ($this->dateTo === null || $this->dateTo > $other->dateFrom) &&
            ($other->dateTo === null || $other->dateTo > $this->dateFrom);
    }

    /**
     * D13 — classified by the period, never by `dateFrom` alone.
     */
    public function zoneAt(string $today): string
    {
        if ($this->dateTo !== null && $this->dateTo <= $today) {
            return self::ZONE_ENDED;
        }

        if ($this->dateFrom > $today) {
            return self::ZONE_PLANNED;
        }

        return self::ZONE_LIVE;
    }
}
