<?php

namespace Espo\Modules\TeamBoard\Hooks\User;

use Espo\Core\Hook\Hook\AfterRelate;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Hook\Hook\AfterUnrelate;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Dashboard\Reconciler;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RelateOptions;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Repository\Option\UnrelateOptions;

/**
 * Catches membership changes made from the user side — the Teams field in the
 * user profile or in the admin UI, and import.
 *
 * Saving that field fires both the relate/unrelate hooks and afterSave, so the
 * reconciler runs more than once per change. That is deliberate redundancy: the
 * operation is idempotent, and the two mechanisms cover each other.
 *
 * @implements AfterRelate<User>
 * @implements AfterUnrelate<User>
 * @implements AfterSave<User>
 */
class ReconcileTeams implements AfterRelate, AfterUnrelate, AfterSave
{
    public static int $order = 20;

    public function __construct(private Reconciler $reconciler) {}

    public function afterRelate(
        Entity $entity,
        string $relationName,
        Entity $relatedEntity,
        array $columnData,
        RelateOptions $options
    ): void {

        $this->handleRelation($entity, $relationName);
    }

    public function afterUnrelate(
        Entity $entity,
        string $relationName,
        Entity $relatedEntity,
        UnrelateOptions $options
    ): void {

        $this->handleRelation($entity, $relationName);
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof User || !$entity->isAttributeChanged('teamsIds')) {
            return;
        }

        $this->reconciler->reconcile($entity->getId());
    }

    private function handleRelation(Entity $entity, string $relationName): void
    {
        if ($relationName !== User::LINK_TEAMS || !$entity instanceof User) {
            return;
        }

        $this->reconciler->reconcile($entity->getId());
    }
}
