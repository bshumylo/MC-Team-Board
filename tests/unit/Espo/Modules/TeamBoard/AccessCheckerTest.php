<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Table;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Classes\Acl\TeamBoardMember\AccessChecker;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class AccessCheckerTest extends TestCase
{
    public function testReadDelegatesToTheTeamBoardReadAcl(): void
    {
        $user = $this->createStub(User::class);
        $data = $this->createStub(ScopeData::class);
        $aclManager = $this->createMock(AclManager::class);

        $aclManager
            ->expects($this->exactly(2))
            ->method('checkScope')
            ->with($user, 'TeamBoard', Table::ACTION_READ)
            ->willReturn(true);

        $checker = new AccessChecker($aclManager, $this->createStub(EntityManager::class));

        $this->assertTrue($checker->check($user, $data));
        $this->assertTrue($checker->checkRead($user, $data));
    }

    public function testEntityReadUsesTheSameReadAcl(): void
    {
        $user = $this->createStub(User::class);
        $entity = $this->createStub(Entity::class);
        $data = $this->createStub(ScopeData::class);
        $aclManager = $this->createMock(AclManager::class);

        $aclManager
            ->expects($this->once())
            ->method('checkScope')
            ->with($user, 'TeamBoard', Table::ACTION_READ)
            ->willReturn(true);

        $checker = new AccessChecker($aclManager, $this->createStub(EntityManager::class));

        $this->assertTrue($checker->checkEntityRead($user, $entity, $data));
    }

    /**
     * P13: a board record of a CRM user never makes that user readable
     * through the board. Entity read also requires the native User read ACL.
     */
    public function testEntityReadOfALinkedMemberRequiresNativeUserReadAccess(): void
    {
        $user = $this->createStub(User::class);
        $data = $this->createStub(ScopeData::class);
        $crmUser = $this->createStub(User::class);
        $member = $this->createStub(Entity::class);
        $member->method('get')->willReturnMap([['userId', 'crm-1']]);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')->with(User::ENTITY_TYPE, 'crm-1')->willReturn($crmUser);

        $aclManager = $this->createMock(AclManager::class);
        $aclManager->method('checkScope')->willReturn(true);
        $aclManager->expects($this->once())
            ->method('checkEntityRead')
            ->with($user, $crmUser)
            ->willReturn(false);

        $checker = new AccessChecker($aclManager, $entityManager);

        $this->assertFalse($checker->checkEntityRead($user, $member, $data));
    }

    public function testEntityReadOfALinkedMemberWithAMissingUserIsDenied(): void
    {
        $user = $this->createStub(User::class);
        $data = $this->createStub(ScopeData::class);
        $member = $this->createStub(Entity::class);
        $member->method('get')->willReturnMap([['userId', 'gone']]);

        $entityManager = $this->createStub(EntityManager::class);
        $entityManager->method('getEntityById')->willReturn(null);

        $aclManager = $this->createStub(AclManager::class);
        $aclManager->method('checkScope')->willReturn(true);

        $checker = new AccessChecker($aclManager, $entityManager);

        $this->assertFalse($checker->checkEntityRead($user, $member, $data));
    }

    public function testEntityReadOfAReadableLinkedMemberIsAllowed(): void
    {
        $user = $this->createStub(User::class);
        $data = $this->createStub(ScopeData::class);
        $crmUser = $this->createStub(User::class);
        $member = $this->createStub(Entity::class);
        $member->method('get')->willReturnMap([['userId', 'crm-1']]);

        $entityManager = $this->createStub(EntityManager::class);
        $entityManager->method('getEntityById')->willReturn($crmUser);

        $aclManager = $this->createStub(AclManager::class);
        $aclManager->method('checkScope')->willReturn(true);
        $aclManager->method('checkEntityRead')->willReturn(true);

        $checker = new AccessChecker($aclManager, $entityManager);

        $this->assertTrue($checker->checkEntityRead($user, $member, $data));
    }

    public function testAllMutationsRemainDeniedToTheGenericEntityAcl(): void
    {
        $user = $this->createStub(User::class);
        $entity = $this->createStub(Entity::class);
        $data = $this->createStub(ScopeData::class);
        $checker = new AccessChecker($this->createStub(AclManager::class), $this->createStub(EntityManager::class));

        $this->assertFalse($checker->checkCreate($user, $data));
        $this->assertFalse($checker->checkEntityCreate($user, $entity, $data));
        $this->assertFalse($checker->checkEdit($user, $data));
        $this->assertFalse($checker->checkEntityEdit($user, $entity, $data));
        $this->assertFalse($checker->checkDelete($user, $data));
        $this->assertFalse($checker->checkEntityDelete($user, $entity, $data));
    }
}
