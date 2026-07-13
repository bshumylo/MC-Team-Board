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
     * The top (exclusive) position — the first one in the list,
     * not counting Supervisor, which is a special out-of-hierarchy position.
     *
     * @param string[] $list
     */
    public static function topOf(array $list): ?string
    {
        foreach ($list as $item) {
            if ($item !== self::SUPERVISOR) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The bottom position — the default one for new members.
     *
     * @param string[] $list
     */
    public static function bottomOf(array $list): string
    {
        $withoutSupervisor = array_values(
            array_filter($list, fn ($item) => $item !== self::SUPERVISOR)
        );

        if ($withoutSupervisor !== []) {
            return end($withoutSupervisor);
        }

        return $list !== [] ? end($list) : self::MEMBER;
    }
}
