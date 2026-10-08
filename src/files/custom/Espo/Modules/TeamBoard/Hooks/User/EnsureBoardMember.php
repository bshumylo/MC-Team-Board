<?php

namespace Espo\Modules\TeamBoard\Hooks\User;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\Modules\TeamBoard\Tools\Timeline\CrmStateRecorder;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements AfterSave<User> */
class EnsureBoardMember implements AfterSave
{
    public function __construct(private Registry $registry, private CrmStateRecorder $recorder) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isNew() || !(bool) $entity->get('isActive')) {
            return;
        }

        if (!in_array($entity->get('type'), [User::TYPE_REGULAR, User::TYPE_ADMIN], true)) {
            return;
        }

        $this->registry->memberForUser($entity->getId());
        $this->recorder->recordForUser($entity->getId());
    }
}
