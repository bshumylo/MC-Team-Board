<?php

namespace Espo\Modules\TeamBoard\Tools\Dashboard;

use Espo\Core\Acl\Table;
use Espo\Core\AclManager;
use Espo\Core\Utils\Log;
use Espo\Entities\Preferences;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\ORM\Name\Attribute;
use stdClass;
use Throwable;

/**
 * Brings a user's personal dashlet options back in line with what their ACL
 * actually allows.
 *
 * Team membership is rotated regularly; the team chosen in a dashlet is a
 * snapshot taken when the dashlet was configured. Once the member is moved,
 * the Calendar dashlet answers with
 * "User Calendar Permission not allowing to view calendars of other teams.".
 *
 * Validity is decided by the user's own ACL, never by the raw membership list:
 * a user with the `all` permission level may legitimately reference teams they
 * do not belong to, and that configuration must survive untouched.
 *
 * The operation is idempotent — a second run finds everything valid and writes
 * nothing — so the overlapping hooks that trigger it are safe.
 */
class Reconciler
{
    public function __construct(
        private EntityManager $entityManager,
        private AclManager $aclManager,
        private TeamFieldMap $fieldMap,
        private Log $log,
    ) {}

    /**
     * Never fails the operation that triggered it. Moving a member is a
     * deliberate admin action; repairing a dashlet is housekeeping. A failure
     * leaves a stale setting, which is the pre-existing state anyway, and the
     * next trigger repairs it.
     */
    public function reconcile(string $userId): void
    {
        try {
            $this->process($userId);

            // O03: the change also affects whoever lists this user in a
            // dashlet; their access to this user's data may now be gone.
            foreach ($this->findReferencingUserIds($userId) as $otherId) {
                $this->process($otherId);
            }
        }
        catch (Throwable $e) {
            $this->log->error(
                "TeamBoard: dashlet reconciliation failed for user $userId. " . $e->getMessage()
            );
        }
    }

    private function process(string $userId): void
    {
        // A just-created/just-related User can still be an unfetched identity-map
        // instance. A query hydrates it before AclManager builds the user's table.
        $user = $this->entityManager
            ->getRDBRepositoryByClass(User::class)
            ->where(['id' => $userId])
            ->findOne();

        if (!$user || (!$user->isRegular() && !$user->isAdmin())) {
            return;
        }

        $preferences = $this->entityManager->getEntityById(Preferences::ENTITY_TYPE, $userId);

        if (!$preferences) {
            return;
        }

        $options = $preferences->get('dashletsOptions');

        if (!$options instanceof stdClass || get_object_vars($options) === []) {
            return;
        }

        $typeMap = $this->buildTypeMap($preferences->get('dashboardLayout'));

        if ($typeMap === []) {
            return;
        }

        $changed = false;

        foreach (get_object_vars($options) as $dashletId => $dashletOptions) {
            if (!$dashletOptions instanceof stdClass) {
                continue;
            }

            $type = $typeMap[$dashletId] ?? null;

            if ($type === null) {
                // An option with no dashlet in the layout — an orphan.
                continue;
            }

            foreach ($this->fieldMap->get($type) as $definition) {
                if ($this->applyTo($user, $dashletOptions, $definition)) {
                    $changed = true;

                    $this->log->info(
                        "TeamBoard: repaired stale {$definition->name} in dashlet $dashletId " .
                        "for user $userId."
                    );
                }
            }
        }

        if (!$changed) {
            return;
        }

        $preferences->set('dashletsOptions', $options);

        $this->entityManager->saveEntity($preferences);
    }

    /**
     * @return bool Whether the field was changed.
     */
    private function applyTo(User $user, stdClass $dashletOptions, FieldDefinition $definition): bool
    {
        $idsAttribute = $definition->getIdsAttribute();

        $stored = $dashletOptions->$idsAttribute ?? null;

        if (!is_array($stored)) {
            return false;
        }

        $level = $this->aclManager->getPermissionLevel($user, $definition->permission);

        if ($level === Table::LEVEL_ALL) {
            // Every reference is legitimate for this user. Nothing can be stale.
            return false;
        }

        $isTeamField = $definition->entityType === Team::ENTITY_TYPE;

        // Relation hooks run while the User entity can still carry a cached
        // teamsIds value. Query the relation so the second half of a move sees
        // the just-committed membership instead of restoring the old team.
        $teamIds = $this->findTeamIds($user);

        $isValid = function (string $id) use ($user, $definition, $isTeamField, $level, $teamIds): bool {
            if ($isTeamField) {
                return $level !== Table::LEVEL_NO && in_array($id, $teamIds, true);
            }

            return $this->aclManager->checkUserPermission($user, $id, $definition->permission);
        };

        // A stale team field is reset to the teams the member now belongs to, so
        // the dashlet shows the new team without anyone touching it. A user
        // field loses only the people the user may no longer see (O04): the
        // permitted ones stay, and no colleagues are picked for the member.
        $newIds = $isTeamField
            ? ValueRule::apply($stored, $isValid, $level !== Table::LEVEL_NO ? $teamIds : [])
            : ValueRule::keepValid($stored, $isValid);

        if ($newIds === null) {
            return false;
        }

        $namesAttribute = $definition->getNamesAttribute();

        $dashletOptions->$idsAttribute = $newIds;
        $dashletOptions->$namesAttribute = (object) $this->fetchNames($definition->entityType, $newIds);

        return true;
    }

    /**
     * Owners of stored preferences that mention the user id. A cheap text
     * pre-filter; process() then decides by the owner's own ACL.
     *
     * @return string[]
     */
    private function findReferencingUserIds(string $userId): array
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from(Preferences::ENTITY_TYPE)
            ->select([Attribute::ID, 'data'])
            ->build();

        $ids = [];
        $needle = '"' . $userId . '"';

        foreach ($this->entityManager->getQueryExecutor()->execute($query)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $id = (string) $row[Attribute::ID];

            if ($id !== $userId && is_string($row['data']) && str_contains($row['data'], $needle)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return string[] */
    private function findTeamIds(User $user): array
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('TeamUser')
            ->select(['teamId'])
            ->where([
                'userId' => $user->getId(),
                'deleted' => false,
            ])
            ->build();

        $statement = $this->entityManager->getQueryExecutor()->execute($query);
        $ids = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $ids[] = $row['teamId'];
        }

        return $ids;
    }

    /**
     * Names are rebuilt from the database rather than carried over, so renamed
     * records do not leave stale labels behind.
     *
     * @param string[] $ids
     * @return array<string, ?string>
     */
    private function fetchNames(string $entityType, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = [];

        $collection = $this->entityManager
            ->getRDBRepository($entityType)
            ->select([Attribute::ID, 'name'])
            ->where([Attribute::ID => $ids])
            ->find();

        foreach ($collection as $entity) {
            $names[$entity->getId()] = $entity->get('name');
        }

        return $names;
    }

    /**
     * Dashlet instance id => dashlet type. The type lives in the layout, while
     * the options are keyed by instance id.
     *
     * @return array<string, string>
     */
    private function buildTypeMap(mixed $layout): array
    {
        if (!is_array($layout)) {
            return [];
        }

        $map = [];

        foreach ($layout as $tab) {
            $items = is_object($tab) ?
                ($tab->layout ?? null) :
                ($tab['layout'] ?? null);

            // Older dashboards are a flat list of dashlets rather than tabs.
            if ($items === null && (is_object($tab) || is_array($tab))) {
                $items = [$tab];
            }

            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $id = is_object($item) ? ($item->id ?? null) : ($item['id'] ?? null);
                $name = is_object($item) ? ($item->name ?? null) : ($item['name'] ?? null);

                if (is_string($id) && is_string($name)) {
                    $map[$id] = $name;
                }
            }
        }

        return $map;
    }
}
