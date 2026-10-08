<?php

namespace Espo\Modules\TeamBoard\Classes\Select\TeamBoardMember\AccessControlFilters;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\AccessControl\Filter;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\ORM\Query\Part\Condition;
use Espo\ORM\Query\Part\Expression;
use Espo\ORM\Query\Part\Where\OrGroup;
use Espo\ORM\Query\SelectBuilder;

/**
 * P13: a board person list never makes an unavailable CRM user available.
 * Board-only people (no userId) are always listed for Team Board readers;
 * a CRM user's record is listed only while that user is active and readable
 * under the current user's native User read ACL (all / team / own / no).
 */
class Mandatory implements Filter
{
    public function __construct(
        private User $user,
        private SelectBuilderFactory $selectBuilderFactory,
    ) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        $orGroup = OrGroup::createBuilder()
            ->add(Condition::equal(Expression::column('userId'), null));

        $visibleUsers = $this->visibleUsersQuery();

        if ($visibleUsers) {
            $orGroup->add(Condition::in(Expression::column('userId'), $visibleUsers));
        }

        $queryBuilder->where($orGroup->build());
    }

    private function visibleUsersQuery(): ?\Espo\ORM\Query\Select
    {
        try {
            return $this->selectBuilderFactory
                ->create()
                ->forUser($this->user)
                ->from(User::ENTITY_TYPE)
                ->withStrictAccessControl()
                ->buildQueryBuilder()
                ->select('id')
                ->where([
                    'isActive' => true,
                    'type' => [User::TYPE_REGULAR, User::TYPE_ADMIN],
                ])
                ->build();
        }
        catch (Forbidden) {
            return null;
        }
    }
}
