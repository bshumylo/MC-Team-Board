<?php

namespace Espo\Modules\TeamBoard\Hooks\Team;

use Espo\Core\Hook\Hook\AfterRelate;
use Espo\Core\Hook\Hook\AfterUnrelate;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Timeline\CrmChangeHandler;
use Espo\Modules\TeamBoard\Tools\Timeline\FuturePlanResetter;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RelateOptions;
use Espo\ORM\Repository\Option\UnrelateOptions;

/**
 * @implements AfterRelate<Team>
 * @implements AfterUnrelate<Team>
 */
class ResetFuturePlans implements AfterRelate, AfterUnrelate
{
    public static int $order = 30;

    public function __construct(private CrmChangeHandler $handler) {}

    public function afterRelate(
        Entity $entity,
        string $relationName,
        Entity $relatedEntity,
        array $columnData,
        RelateOptions $options
    ): void {
        if (!$options->get(FuturePlanResetter::SKIP_OPTION)) {
            $this->reset($relationName, $relatedEntity);
        }
    }

    public function afterUnrelate(
        Entity $entity,
        string $relationName,
        Entity $relatedEntity,
        UnrelateOptions $options
    ): void {
        if (!$options->get(FuturePlanResetter::SKIP_OPTION)) {
            $this->reset($relationName, $relatedEntity);
        }
    }

    private function reset(string $relationName, Entity $relatedEntity): void
    {
        if ($relationName === 'users' && $relatedEntity instanceof User &&
            in_array($relatedEntity->get('type'), [User::TYPE_REGULAR, User::TYPE_ADMIN], true)) {
            $this->handler->handle($relatedEntity->getId());
        }
    }
}
