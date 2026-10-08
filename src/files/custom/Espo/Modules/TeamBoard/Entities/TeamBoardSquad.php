<?php

namespace Espo\Modules\TeamBoard\Entities;

use Espo\Core\ORM\Entity;

class TeamBoardSquad extends Entity
{
    public const ENTITY_TYPE = 'TeamBoardSquad';

    public const COLOUR_SLATE = 'slate';
    public const COLOUR_LIST = [
        self::COLOUR_SLATE,
        'blue',
        'sky',
        'teal',
        'green',
        'lime',
        'amber',
        'orange',
        'rose',
        'pink',
        'violet',
        'purple',
        'indigo',
    ];

    public const DEFAULT_POSITION_LIST = [
        'Supervisor',
        'Leader',
        'Vice Leader',
        'Member',
    ];
}
