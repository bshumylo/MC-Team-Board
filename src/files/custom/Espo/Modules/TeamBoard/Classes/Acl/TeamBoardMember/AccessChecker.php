<?php

namespace Espo\Modules\TeamBoard\Classes\Acl\TeamBoardMember;

use Espo\Core\Acl\AccessEntityCREDChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Table;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Lets Team Board readers load related board-photo attachments and list board
 * people. A record of a CRM user is readable only while that CRM user is
 * readable under the native User read ACL (P13): the board never makes an
 * unavailable person available. Mutations of the internal wrapper remain
 * available only through the allow-listed board management service.
 *
 * @implements AccessEntityCREDChecker<Entity>
 */
class AccessChecker implements AccessEntityCREDChecker
{
    private const SCOPE = 'TeamBoard';

    public function __construct(
        private AclManager $aclManager,
        private EntityManager $entityManager,
    ) {}

    public function check(User $user, ScopeData $data): bool
    {
        return $this->checkRead($user, $data);
    }

    public function checkRead(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, self::SCOPE, Table::ACTION_READ);
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        if (!$this->checkRead($user, $data)) {
            return false;
        }

        $userId = $entity->get('userId');

        if (!is_string($userId) || $userId === '') {
            return true;
        }

        $crmUser = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);

        if (!$crmUser) {
            return false;
        }

        return $this->aclManager->checkEntityRead($user, $crmUser);
    }

    public function checkCreate(User $user, ScopeData $data): bool
    {
        return false;
    }

    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool
    {
        return false;
    }

    public function checkEdit(User $user, ScopeData $data): bool
    {
        return false;
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return false;
    }

    public function checkDelete(User $user, ScopeData $data): bool
    {
        return false;
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return false;
    }
}
