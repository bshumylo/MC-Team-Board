<?php

namespace Espo\Modules\TeamBoard\Tools\Dashboard;

use Espo\Core\Acl\Permission;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Team;
use Espo\Entities\User;

/**
 * Which option fields of a dashlet reference Teams or Users.
 *
 * Answers only that question: it knows nothing about users, preferences or
 * access. Built from the `dashlets` metadata section and cached per request.
 */
class TeamFieldMap
{
    private const ACL_SCOPE_CALENDAR = 'Calendar';
    private const TYPE_LINK_MULTIPLE = 'linkMultiple';

    /** @var ?array<string, FieldDefinition[]> */
    private ?array $map = null;

    public function __construct(private Metadata $metadata) {}

    /**
     * @return FieldDefinition[]
     */
    public function get(string $dashletType): array
    {
        $this->map ??= $this->build();

        return $this->map[$dashletType] ?? [];
    }

    /**
     * @return array<string, FieldDefinition[]>
     */
    private function build(): array
    {
        $map = [];

        $dashlets = $this->metadata->get(['dashlets']) ?? [];

        if (!is_array($dashlets)) {
            return $map;
        }

        foreach ($dashlets as $type => $defs) {
            $fields = $defs['options']['fields'] ?? null;

            if (!is_array($fields)) {
                continue;
            }

            $permission = self::permissionFor($defs['aclScope'] ?? null);

            foreach ($fields as $name => $def) {
                if (!is_array($def) || ($def['type'] ?? null) !== self::TYPE_LINK_MULTIPLE) {
                    continue;
                }

                $entityType = $def['entity'] ?? null;

                if ($entityType !== Team::ENTITY_TYPE && $entityType !== User::ENTITY_TYPE) {
                    continue;
                }

                $map[$type][] = new FieldDefinition($name, $entityType, $permission);
            }
        }

        return $map;
    }

    /**
     * The permission that governs the reference. Calendar dashlets are bound by
     * the user-calendar permission; anything else by the general user permission.
     */
    private static function permissionFor(?string $aclScope): string
    {
        return $aclScope === self::ACL_SCOPE_CALENDAR ?
            Permission::USER_CALENDAR :
            Permission::USER;
    }
}
