<?php

namespace Espo\Modules\TeamBoard\Tools\Install;

use Espo\ORM\EntityManager;

/**
 * Creates the ScheduledJob rows for the extension's cron jobs. EspoCRM only
 * populates them via a CLI command, so install/upgrade must ensure them.
 * Idempotent: an existing row (any status, edited schedule) is left untouched.
 */
class ScheduledJobsInstaller
{
    private const ENTITY_TYPE = 'ScheduledJob';

    /** @var array<string, array{name: string, scheduling: string}> */
    private const JOBS = [
        'TeamBoardApplyDueAssignments' => [
            'name' => 'Team Board: apply due assignments',
            'scheduling' => '10 0 * * *',
        ],
        'TeamBoardCleanupMissedDrafts' => [
            'name' => 'Team Board: clean up missed drafts',
            'scheduling' => '20 0 * * *',
        ],
    ];

    public function __construct(private EntityManager $entityManager)
    {}

    public function run(): void
    {
        foreach (self::JOBS as $job => $def) {
            $existing = $this->entityManager
                ->getRDBRepository(self::ENTITY_TYPE)
                ->where(['job' => $job])
                ->findOne();

            if ($existing) {
                continue;
            }

            $this->entityManager->createEntity(self::ENTITY_TYPE, [
                'name' => $def['name'],
                'job' => $job,
                'scheduling' => $def['scheduling'],
                'status' => 'Active',
            ]);
        }
    }
}
