<?php

namespace Espo\Modules\TeamBoard\Tools\Dashboard;

/**
 * A dashlet option field that references Teams or Users.
 *
 * Discovered from dashlet metadata, not hard-coded, so dashlets added later
 * (including ones from other extensions) are covered without a code change.
 */
class FieldDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $entityType,
        public readonly string $permission,
    ) {}

    public function getIdsAttribute(): string
    {
        return $this->name . 'Ids';
    }

    public function getNamesAttribute(): string
    {
        return $this->name . 'Names';
    }
}
