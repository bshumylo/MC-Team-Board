<?php

namespace Espo\Modules\TeamBoard\Tools\Shadow;

final class BackfillReport
{
    public function __construct(
        public readonly int $memberCount,
        public readonly int $squadCount,
        public readonly int $assignmentCount,
    ) {}
}
