<?php

namespace Espo\Modules\TeamBoard\Hooks\Team;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Entities\Team;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements AfterSave<Team> */
class EnsureBoardSquad implements AfterSave
{
    public function __construct(private Registry $registry) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        $this->registry->squadForTeam($entity->getId());
    }
}
