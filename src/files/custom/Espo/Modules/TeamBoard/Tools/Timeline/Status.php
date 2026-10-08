<?php

namespace Espo\Modules\TeamBoard\Tools\Timeline;

class Status
{
    public const DRAFT = 'draft';
    public const CONFIRMED = 'confirmed';

    /** @var string[] */
    public const LIST = [self::DRAFT, self::CONFIRMED];
}
