<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Modules\TeamBoard\Tools\Install\ScheduledJobsInstaller;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class ScheduledJobsInstallerTest extends TestCase
{
    /**
     * @param array<string, bool> $existing job => exists
     * @return array{EntityManager, list<array<string, mixed>>}
     */
    private function install(array $existing): array
    {
        $created = [];
        $repository = $this->createStub(RDBRepository::class);
        $repository->method('where')->willReturnCallback(function (array $where) use ($existing) {
            $select = $this->createStub(RDBSelectBuilder::class);
            $select->method('findOne')->willReturn(
                ($existing[$where['job']] ?? false) ? $this->createStub(Entity::class) : null
            );
            return $select;
        });
        $em = $this->createStub(EntityManager::class);
        $em->method('getRDBRepository')->with('ScheduledJob')->willReturn($repository);
        $em->method('createEntity')->willReturnCallback(function (string $type, array $data) use (&$created) {
            $this->assertSame('ScheduledJob', $type);
            $created[] = $data;
            return $this->createStub(Entity::class);
        });
        (new ScheduledJobsInstaller($em))->run();

        return [$em, $created];
    }

    public function testCreatesBothMissingRows(): void
    {
        [, $created] = $this->install([]);

        $this->assertSame([
            ['name' => 'Team Board: apply due assignments', 'job' => 'TeamBoardApplyDueAssignments',
                'scheduling' => '10 0 * * *', 'status' => 'Active'],
            ['name' => 'Team Board: clean up missed drafts', 'job' => 'TeamBoardCleanupMissedDrafts',
                'scheduling' => '20 0 * * *', 'status' => 'Active'],
        ], $created);
    }

    public function testExistingRowIsNeverDuplicatedOrOverwritten(): void
    {
        [, $created] = $this->install(['TeamBoardApplyDueAssignments' => true]);

        $this->assertCount(1, $created);
        $this->assertSame('TeamBoardCleanupMissedDrafts', $created[0]['job']);
        [, $none] = $this->install([
            'TeamBoardApplyDueAssignments' => true, 'TeamBoardCleanupMissedDrafts' => true,
        ]);
        $this->assertSame([], $none);
    }
}
