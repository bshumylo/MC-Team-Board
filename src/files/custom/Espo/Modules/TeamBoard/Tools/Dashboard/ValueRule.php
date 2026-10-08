<?php

namespace Espo\Modules\TeamBoard\Tools\Dashboard;

/**
 * The repair rule for one stored list of ids.
 *
 * Kept free of infrastructure on purpose: this is the subtlest part of the
 * feature, and it is unit-testable without a database.
 *
 * A partially valid list is never preserved. What is left of it after a
 * rotation is a stale slice of a previous membership: it raises no access
 * error, but it does not show the member their new team either.
 */
class ValueRule
{
    /**
     * @param mixed[] $stored Currently stored ids.
     * @param callable(string): bool $isValid Whether an id is still allowed for the user.
     * @param string[] $fallback What to store instead when the list is stale.
     * @return ?string[] New ids, or null when nothing has to change.
     */
    public static function apply(array $stored, callable $isValid, array $fallback): ?array
    {
        if ($stored === []) {
            return null;
        }

        foreach ($stored as $id) {
            if (!is_string($id) || !$isValid($id)) {
                return array_values($fallback);
            }
        }

        return null;
    }

    /**
     * O04 — the per-item rule for people lists: only ids the user may no
     * longer reference are dropped; permitted (also cross-team) ids are kept.
     * A wholly invalid list becomes empty.
     *
     * @param mixed[] $stored Currently stored ids.
     * @param callable(string): bool $isValid Whether an id is still allowed for the user.
     * @return ?string[] New ids, or null when nothing has to change.
     */
    public static function keepValid(array $stored, callable $isValid): ?array
    {
        $kept = [];
        $changed = false;

        foreach ($stored as $id) {
            if (is_string($id) && $isValid($id)) {
                $kept[] = $id;

                continue;
            }

            $changed = true;
        }

        return $changed ? $kept : null;
    }
}
