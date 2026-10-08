<?php

namespace Espo\Modules\TeamBoard\Entities;

use Espo\Core\ORM\Entity;

/** A dated visibility transition for a board-only squad. */
class TeamBoardSquadVisibility extends Entity
{
    public const ENTITY_TYPE = 'TeamBoardSquadVisibility';

    public const ACTIVE = 'active';
    public const ARCHIVED = 'archived';
}
