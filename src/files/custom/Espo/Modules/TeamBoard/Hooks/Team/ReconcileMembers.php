<?php

namespace Espo\Modules\TeamBoard\Hooks\Team;

use Espo\Core\Hook\Hook\AfterRelate;
use Espo\Core\Hook\Hook\AfterUnrelate;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Dashboard\Reconciler;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RelateOptions;
use Espo\ORM\Repository\Option\UnrelateOptions;

/**
 * Catches membership changes made from the team side — which is what the board
 * does when it moves a member between columns.
 *
 * @implements AfterRelate<Team>
 * @implements AfterUnrelate<Team>
 */
class ReconcileMembers implements AfterRelate, AfterUnrelate
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

        $this->handle($relationName, $relatedEntity);
    }

    public function afterUnrelate(
        Entity $entity,
        string $relationName,
        Entity $relatedEntity,
        UnrelateOptions $options
    ): void {

        $this->handle($relationName, $relatedEntity);
    }

    private function handle(string $relationName, Entity $relatedEntity): void
    {
        if ($relationName !== 'users' || !$relatedEntity instanceof User) {
            return;
        }

        $this->reconciler->reconcile($relatedEntity->getId());
    }
}
