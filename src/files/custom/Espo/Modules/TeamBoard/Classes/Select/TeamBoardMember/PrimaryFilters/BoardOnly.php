<?php

namespace Espo\Modules\TeamBoard\Classes\Select\TeamBoardMember\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder;

class BoardOnly implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where(['userId' => null, 'isArchived' => false]);
    }
}
