<?php

namespace Espo\Modules\TeamBoard\Tools\Board;

/**
 * Team positions used by the Team Board.
 *
 * Positions are contextual (per-team) and are stored in the `role` column
 * of the Team-User relationship (the native EspoCRM team position mechanism).
 * They are not related to global ACL Roles.
 */
class Position
{
    public const SUPERVISOR = 'Supervisor';
    public const LEADER = 'Leader';
    public const VICE_LEADER = 'Vice Leader';
    public const MEMBER = 'Member';

    /** @var string[] */
    public const LIST = [
        self::SUPERVISOR,
        self::LEADER,
        self::VICE_LEADER,
        self::MEMBER,
    ];

    /** Positions that can be held by only one user per team. */
    public const EXCLUSIVE_LIST = [
        self::LEADER,
        self::VICE_LEADER,
    ];
}
