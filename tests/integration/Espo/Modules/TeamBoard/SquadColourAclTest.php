<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\Team;
use Espo\Modules\TeamBoard\Tools\Shadow\ManagementService;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\ORM\EntityManager;
use tests\integration\Core\BaseTestCase;

/**
 * F9: changing the colour of a linked squad must respect the read ACL of the
 * linked Team, like Timeline\Service::findSquads does for the board payload.
 */
class SquadColourAclTest extends BaseTestCase
{
    private function entityManager(): EntityManager
    {
        return $this->getContainer()->getByClass(EntityManager::class);
    }

    private function colour(string $squadId): ?string
    {
        return $this->entityManager()->getEntityById('TeamBoardSquad', $squadId)?->get('colourKey');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function linkedSquad(): array
    {
        /** @var Team $team */
        $team = $this->entityManager()->createEntity(Team::ENTITY_TYPE, [
            'name' => 'Hidden linked team',
            'positionList' => ['Leader', 'Member'],
        ]);
        $squad = $this->getInjectableFactory()->create(Registry::class)->squadForTeam($team->getId());
        $squad->set('colourKey', 'slate');
        $this->entityManager()->saveEntity($squad);

        return [$team->getId(), $squad->getId()];
    }

    public function testEditorWithoutTeamReadCannotChangeLinkedSquadColour(): void
    {
        [, $squadId] = $this->linkedSquad();

        foreach (['own', 'no'] as $teamRead) {
            $editor = $this->createUser('f9.editor.' . $teamRead, ['data' => [
                'TeamBoard' => true,
                'TeamBoardAssignment' => ['read' => 'all', 'edit' => 'all', 'create' => 'yes'],
                'Team' => ['read' => $teamRead, 'edit' => 'no'],
            ]]);
            $this->authenticate($editor->getUserName());
            $management = $this->getInjectableFactory()->create(ManagementService::class);

            try {
                $management->updateSquad($squadId, (object) ['colourKey' => 'violet']);
                $this->fail("Colour of a hidden team's squad was changed with Team read={$teamRead}.");
            } catch (Forbidden) {
                $this->assertSame('slate', $this->colour($squadId));
            }
        }
    }

    public function testEditorWithTeamReadAllCanChangeLinkedSquadColour(): void
    {
        [, $squadId] = $this->linkedSquad();
        $editor = $this->createUser('f9.editor.all', ['data' => [
            'TeamBoard' => true,
            'TeamBoardAssignment' => ['read' => 'all', 'edit' => 'all', 'create' => 'yes'],
            'Team' => ['read' => 'all', 'edit' => 'no'],
        ]]);
        $this->authenticate($editor->getUserName());

        $this->getInjectableFactory()->create(ManagementService::class)
            ->updateSquad($squadId, (object) ['colourKey' => 'violet']);

        $this->assertSame('violet', $this->colour($squadId));
    }
}
