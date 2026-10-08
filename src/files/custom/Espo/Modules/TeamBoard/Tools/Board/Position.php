<?php

namespace Espo\Modules\TeamBoard\Tools\Board;

/**
 * Team positions used by the Team Board.
 *
 * Positions are contextual (per-team) and are stored in the `role` column
 * of the Team-User relationship (the native team position mechanism).
 * They are not related to global ACL Roles.
 *
 * The actual position list is dynamic — taken from the `positionList`
 * field of each Team. The constants below are only the fallback used
 * when a team has no own position list defined.
 */
class Position
{
    public const SUPERVISOR = 'Supervisor';
    public const LEADER = 'Leader';
    public const VICE_LEADER = 'Vice Leader';
    public const MEMBER = 'Member';

    /**
     * Fallback list for teams without an own position list.
     *
     * @var string[]
     */
    public const DEFAULT_LIST = [
        self::SUPERVISOR,
        self::LEADER,
        self::VICE_LEADER,
        self::MEMBER,
    ];

    /**
     * Position list of a team; falls back to the default list.
     *
     * @param ?string[] $teamPositionList
     * @return string[]
     */
    public static function listFor(?array $teamPositionList): array
    {
        $list = array_values(array_filter(
            $teamPositionList ?? [],
            fn ($item) => is_string($item) && $item !== ''
        ));

        return $list !== [] ? $list : self::DEFAULT_LIST;
    }

    /**
     * Whether a position may be held by only one person at a time.
     *
     * Exclusivity follows the order of the team's list, not position names
     * (T08, changed 2026-09-25): positions 1-3 are exclusive, except the last
     * position of the list, which is always the shared ordinary rank. The
     * first position (Supervisor or any other name) is exclusive too.
     * Positions from the 4th on are shared. Taking an exclusive position over
     * splits the previous holder's history and demotes them; existing data
     * with several holders is not rewritten by this check.
     *
     * @param string[] $list
     */
    public static function isExclusive(array $list, string $position): bool
    {
        if ($position === '') {
            return false;
        }

        $list = self::listFor($list);
        $index = array_search($position, $list, true);

        if ($index === false) {
            return false;
        }

        return $index < 3 && $index !== count($list) - 1;
    }

    /**
     * The bottom position — the default one for new members and the one a
     * demoted holder of an exclusive position moves to.
     *
     * It is the last position of the team's list, decided by order and not
     * by name (T08, U16): the last position is always the ordinary rank.
     *
     * @param string[] $list
     */
    public static function bottomOf(array $list): string
    {
        $list = self::listFor($list);

        return (string) end($list);
    }
}
