<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\MassAction\Params as MassActionParams;
use Espo\Core\MassAction\Service as MassActionService;
use Espo\Core\MassAction\ServiceParams as MassActionServiceParams;
use Espo\Core\Select\SearchParams;
use Espo\Core\Exceptions\ForbiddenSilent;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\ORM\EntityManager;
use Espo\Tools\Export\Params as ExportParams;
use Espo\Tools\Export\Service as ExportService;
use Espo\Tools\Export\ServiceParams as ExportServiceParams;
use stdClass;
use tests\integration\Core\BaseTestCase;

/**
 * F5/F8: the controller-less generic POST /MassAction and POST /Export must not
 * reach TeamBoardAssignment, even for a role that holds every permission on the
 * scope, because they would bypass Timeline\Service (zones, positions, ACL).
 */
class AssignmentLockdownTest extends BaseTestCase
{
    private const ASSIGNMENT = 'TeamBoardAssignment';

    private function entityManager(): EntityManager
    {
        return $this->getContainer()->getByClass(EntityManager::class);
    }

    public function testMassActionsAndExportAreRefusedForAnAllPermissionsRole(): void
    {
        $em = $this->entityManager();
        $team = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'Lockdown team']);
        $target = $em->createEntity(User::ENTITY_TYPE, [
            'userName' => 'lockdown.target', 'lastName' => 'lockdown.target', 'type' => User::TYPE_REGULAR,
        ]);
        $plan = $em->createEntity(self::ASSIGNMENT, [
            'memberId' => $target->getId(), 'teamId' => $team->getId(), 'position' => Position::MEMBER,
            'dateFrom' => '2999-01-01', 'dateTo' => null, 'status' => Status::DRAFT,
        ]);
        $before = $em->getEntityById(self::ASSIGNMENT, $plan->getId())?->getValueMap();
        $this->assertNotNull($before);

        $this->createUser('lockdown.editor', [
            'massUpdatePermission' => 'yes',
            'exportPermission' => 'yes',
            'data' => [
                'TeamBoard' => true,
                'TeamBoardAssignment' => ['create' => 'yes', 'read' => 'all', 'edit' => 'all', 'delete' => 'all'],
                'Team' => ['read' => 'all', 'edit' => 'no'],
                'User' => ['read' => 'all', 'edit' => 'no'],
            ],
        ]);
        $this->authenticate('lockdown.editor');

        $massService = $this->getInjectableFactory()->create(MassActionService::class);

        foreach ([['update', (object) ['values' => (object) ['position' => Position::LEADER]]],
                  ['delete', (object) []]] as [$action, $data]) {
            foreach ([
                MassActionParams::createWithIds(self::ASSIGNMENT, [$plan->getId()]),
                MassActionParams::createWithSearchParams(self::ASSIGNMENT, SearchParams::create()),
            ] as $params) {
                $this->assertMassActionRefused($massService, $action, $params, $data);
            }
        }

        $this->assertExportRefused([
            'entityType' => self::ASSIGNMENT, 'ids' => [$plan->getId()], 'format' => 'csv',
        ]);
        $this->assertExportRefused([
            'entityType' => self::ASSIGNMENT, 'searchParams' => [], 'format' => 'csv',
        ]);

        $after = $em->getEntityById(self::ASSIGNMENT, $plan->getId());
        $this->assertNotNull($after, 'The assignment row must survive the refused mass delete.');
        $this->assertEquals($before, $after->getValueMap());
    }

    private function assertMassActionRefused(
        MassActionService $service,
        string $action,
        MassActionParams $params,
        stdClass $data,
    ): void {
        try {
            $service->process(self::ASSIGNMENT, $action, MassActionServiceParams::create($params), $data);
        } catch (Forbidden $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());

            return;
        }

        $this->fail("Mass {$action} on " . self::ASSIGNMENT . ' was not refused.');
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function assertExportRefused(array $raw): void
    {
        $service = $this->getInjectableFactory()->create(ExportService::class);

        try {
            $service->process(ExportParams::fromRaw($raw), ExportServiceParams::create());
        } catch (ForbiddenSilent $e) {
            $this->assertStringContainsString('Export disabled', $e->getMessage());

            return;
        }

        $this->fail('Export of ' . self::ASSIGNMENT . ' was not refused.');
    }
}
