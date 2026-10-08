<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Select\SearchParams;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\ORM\Entity;
use tests\integration\Core\BaseTestCase;

/**
 * P13 (review №23): the «Manage users» list is the native Record list of
 * TeamBoardMember. It never shows a CRM user the current user cannot read
 * under the native User read ACL, hides deactivated CRM users and refuses
 * every generic mutation.
 */
class ManageUsersListTest extends BaseTestCase
{
    private function crmUser(string $userName, string $first, string $last, bool $active = true): User
    {
        /** @var User $user */
        $user = $this->getEntityManager()->createEntity(User::ENTITY_TYPE, [
            'userName' => $userName,
            'firstName' => $first,
            'lastName' => $last,
            'type' => User::TYPE_REGULAR,
            'isActive' => true,
        ]);

        if (!$active) {
            $user->set('isActive', false);
            $this->getEntityManager()->saveEntity($user);
        }

        return $user;
    }

    private function memberOf(User $user): Entity
    {
        return $this->getInjectableFactory()->create(Registry::class)->memberForUser($user->getId());
    }

    private function boardOnly(string $first, string $last, bool $archived = false): Entity
    {
        return $this->getEntityManager()->createEntity('TeamBoardMember', [
            'name' => "$first $last",
            'firstName' => $first,
            'lastName' => $last,
            'isArchived' => $archived,
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function listAs(?string $primaryFilter = null, ?string $orderBy = null, ?string $text = null): array
    {
        $params = SearchParams::create()
            ->withSelect(['id', 'userId', 'personFirstName', 'personLastName', 'origin', 'isArchived'])
            ->withMaxSize(500);

        if ($primaryFilter) {
            $params = $params->withPrimaryFilter($primaryFilter);
        }

        if ($orderBy) {
            $params = $params->withOrderBy($orderBy)->withOrder(SearchParams::ORDER_ASC);
        }

        if ($text) {
            $params = $params->withTextFilter($text);
        }

        $result = $this->getContainer()->getByClass(ServiceContainer::class)
            ->get('TeamBoardMember')
            ->find($params);

        $rows = [];

        foreach ($result->getCollection() as $entity) {
            $rows[$entity->getId()] = $entity->getValueMap() ? (array) $entity->getValueMap() : [];
        }

        return $rows;
    }

    public function testRestrictedUserReadNeverListsUnavailableOrInactiveCrmUsers(): void
    {
        $visible = $this->crmUser('p13.other', 'Olena', 'Other');
        $inactive = $this->crmUser('p13.inactive', 'Ivan', 'Inactive');
        $otherMember = $this->memberOf($visible);
        $inactiveMember = $this->memberOf($inactive);
        $inactive->set('isActive', false);
        $this->getEntityManager()->saveEntity($inactive);
        $boardOnly = $this->boardOnly('Board', 'Only');

        $viewer = $this->createUser('p13.own', ['data' => [
            'TeamBoard' => true, 'User' => ['read' => 'own'],
        ]]);
        $ownMember = $this->memberOf($viewer);

        $this->authenticate($viewer->getUserName());
        $rows = $this->listAs();

        $this->assertArrayHasKey($boardOnly->getId(), $rows, 'Board-only people are listed for board readers.');
        $this->assertArrayHasKey($ownMember->getId(), $rows, 'Own CRM user is readable with User read = own.');
        $this->assertArrayNotHasKey($otherMember->getId(), $rows, 'Unavailable CRM user must not be listed.');
        $this->assertArrayNotHasKey($inactiveMember->getId(), $rows, 'Deactivated CRM user must not be listed.');

        foreach ($rows as $row) {
            $this->assertTrue($row['userId'] === null || $row['userId'] === $viewer->getId());
        }

        $acl = $this->getContainer()->getByClass(Acl::class);
        $this->assertFalse($acl->checkEntityRead($otherMember), 'Single read of an unavailable CRM user is denied.');
        $this->assertTrue($acl->checkEntityRead($boardOnly));
    }

    public function testUserReadNoListsOnlyBoardOnlyPeople(): void
    {
        $this->memberOf($this->crmUser('p13.someone', 'Some', 'One'));
        $boardOnly = $this->boardOnly('Only', 'Board');

        $viewer = $this->createUser('p13.no', ['data' => [
            'TeamBoard' => true, 'User' => ['read' => 'no'],
        ]]);

        $this->authenticate($viewer->getUserName());
        $rows = $this->listAs();

        $this->assertArrayHasKey($boardOnly->getId(), $rows);
        $this->assertSame([null], array_values(array_unique(array_column($rows, 'userId'))));
    }

    public function testNoBoardAccessCannotList(): void
    {
        $viewer = $this->createUser('p13.noboard', ['data' => [
            'TeamBoard' => false, 'User' => ['read' => 'all'],
        ]]);

        $this->authenticate($viewer->getUserName());
        $this->expectException(Forbidden::class);
        $this->listAs();
    }

    public function testPrimaryFiltersOriginAndCrmNamesForAdmin(): void
    {
        $crm = $this->crmUser('p13.names', 'Zenon', 'Aardvark');
        $crmMember = $this->memberOf($crm);
        $inactive = $this->crmUser('p13.gone', 'Gone', 'Inactive');
        $inactiveMember = $this->memberOf($inactive);
        $inactive->set('isActive', false);
        $this->getEntityManager()->saveEntity($inactive);
        $active = $this->boardOnly('Active', 'Boardonly');
        $archived = $this->boardOnly('Archived', 'Boardonly', true);

        $all = $this->listAs('active');
        $this->assertArrayHasKey($crmMember->getId(), $all);
        $this->assertArrayHasKey($active->getId(), $all);
        $this->assertArrayNotHasKey($archived->getId(), $all);
        $this->assertArrayNotHasKey($inactiveMember->getId(), $all, 'Even admin does not see deactivated CRM users.');

        // P01: the CRM name is read dynamically from the User record.
        $this->assertSame('Zenon', $all[$crmMember->getId()]['personFirstName']);
        $this->assertSame('Aardvark', $all[$crmMember->getId()]['personLastName']);
        $this->assertSame('system', $all[$crmMember->getId()]['origin']);
        $this->assertSame('boardOnly', $all[$active->getId()]['origin']);

        $crm->set('lastName', 'Aabbey');
        $this->getEntityManager()->saveEntity($crm);
        $sorted = array_keys($this->listAs('active', 'personLastName'));
        $this->assertSame($crmMember->getId(), $sorted[0], 'Sorting by last name uses the live CRM name.');

        $boardOnly = $this->listAs('boardOnly');
        $this->assertArrayHasKey($active->getId(), $boardOnly);
        $this->assertArrayNotHasKey($crmMember->getId(), $boardOnly);
        $this->assertArrayNotHasKey($archived->getId(), $boardOnly);

        $system = $this->listAs('system');
        $this->assertArrayHasKey($crmMember->getId(), $system);
        $this->assertArrayNotHasKey($active->getId(), $system);

        $archivedList = $this->listAs('archived');
        $this->assertSame([$archived->getId()], array_keys($archivedList));

        $this->assertArrayHasKey($crmMember->getId(), $this->listAs('active', null, 'Zenon'));
        $this->assertArrayNotHasKey($active->getId(), $this->listAs('active', null, 'Zenon'));

        $this->assertArrayHasKey($active->getId(), $this->listAs('active', 'origin'));

        // The board record of a CRM user does not store a copy of the CRM name.
        $stored = $this->getEntityManager()->getEntityById('TeamBoardMember', $crmMember->getId());
        $this->assertNull($stored?->get('lastName'));
    }

    public function testGenericRecordMutationsAreForbidden(): void
    {
        $member = $this->boardOnly('Mutation', 'Target');
        $service = $this->getContainer()->getByClass(ServiceContainer::class)->get('TeamBoardMember');

        foreach ([
            fn () => $service->create((object) ['name' => 'X'], \Espo\Core\Record\CreateParams::create()),
            fn () => $service->update($member->getId(), (object) ['name' => 'Y'], \Espo\Core\Record\UpdateParams::create()),
            fn () => $service->delete($member->getId(), \Espo\Core\Record\DeleteParams::create()),
        ] as $index => $call) {
            try {
                $call();
                $this->fail("Mutation $index was not refused.");
            } catch (Forbidden) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testControllerMutationsAreForbiddenNotServerError(): void
    {
        $controller = $this->getContainer()->getByClass(\Espo\Core\InjectableFactory::class)
            ->create(\Espo\Modules\TeamBoard\Controllers\TeamBoardMember::class);
        $request = $this->createMock(\Espo\Core\Api\Request::class);
        $response = $this->createMock(\Espo\Core\Api\Response::class);

        foreach (['postActionCreate', 'patchActionUpdate', 'putActionUpdate', 'deleteActionDelete'] as $action) {
            try {
                $controller->$action($request, $response);
                $this->fail("$action was not refused.");
            } catch (Forbidden $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
