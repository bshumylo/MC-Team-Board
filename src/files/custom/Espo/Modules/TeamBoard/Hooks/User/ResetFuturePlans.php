<?php

namespace Espo\Modules\TeamBoard\Hooks\User;

use Espo\Core\Hook\Hook\AfterRelate;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Hook\Hook\AfterUnrelate;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Timeline\CrmChangeHandler;
use Espo\Modules\TeamBoard\Tools\Timeline\FuturePlanResetter;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RelateOptions;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Repository\Option\UnrelateOptions;

/**
 * Manual CRM state invalidates approval of future plans, including changes
 * from imports and the native relationship API. Repeated hooks are harmless:
 * only unapplied confirmed plans are reset.
 *
 * @implements AfterSave<User>
 * @implements AfterRelate<User>
 * @implements AfterUnrelate<User>
 */
class ResetFuturePlans implements AfterSave, AfterRelate, AfterUnrelate
{
    public static int $order = 30;

    public function __construct(private CrmChangeHandler $handler) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get(FuturePlanResetter::SKIP_OPTION) || $entity->isNew()) {
            return;
        }

        // A position changed in the Teams field updates only the relationship
        // column; no relate hook fires for it, yet it is a CRM fact (T08).
        if (!$entity->isAttributeChanged('defaultTeamId') && !$entity->isAttributeChanged('teamsIds') &&
            !$entity->isAttributeChanged('teamsColumns') && !$entity->isAttributeChanged('isActive')) {
            return;
        }

        $this->reset($entity);
    }

    public function afterRelate(
        Entity $entity,
        string $relationName,
        Entity $relatedEntity,
        array $columnData,
        RelateOptions $options
    ): void {
        if ($relationName === User::LINK_TEAMS && !$options->get(FuturePlanResetter::SKIP_OPTION)) {
            $this->reset($entity);
        }
    }

    public function afterUnrelate(
        Entity $entity,
        string $relationName,
        Entity $relatedEntity,
        UnrelateOptions $options
    ): void {
        if ($relationName === User::LINK_TEAMS && !$options->get(FuturePlanResetter::SKIP_OPTION)) {
            $this->reset($entity);
        }
    }

    private function reset(Entity $entity): void
    {
        if ($entity instanceof User &&
            in_array($entity->get('type'), [User::TYPE_REGULAR, User::TYPE_ADMIN], true)) {
            $this->handler->handle($entity->getId());
        }
    }
}
