<?php

use Espo\Core\Container;
use Espo\Core\InjectableFactory;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Install\ScheduledJobsInstaller;
use Espo\Modules\TeamBoard\Tools\Shadow\Backfill;
use Espo\Modules\TeamBoard\Tools\Shadow\DisplacementLinkBackfill;
use Espo\Modules\TeamBoard\Tools\Timeline\CrmStateRecorder;
use Espo\ORM\EntityManager;

/**
 * Called when the extension is installed.
 */
class AfterInstall
{
    public function run(Container $container): void
    {
        $em = $container->getByClass(EntityManager::class);
        $factory = $container->getByClass(InjectableFactory::class);
        $em->getTransactionManager()->run(function () use ($em, $factory): void {
            $factory->create(Backfill::class)->run();
            // Before the recorder, so later steps already see owned tails.
            $factory->create(DisplacementLinkBackfill::class)->run();
            $recorder = $factory->create(CrmStateRecorder::class);
            $users = $em->getRDBRepositoryByClass(User::class)
                ->where(['type' => [User::TYPE_REGULAR, User::TYPE_ADMIN]])->find();

            foreach ($users as $user) {
                // Start unknown history today, using only actual CRM default.
                // Existing facts and repeat installations are reconciled by
                // the same idempotent recorder as manual CRM changes.
                $recorder->recordForUser($user->getId());
            }

            // Cron rows are not created by Rebuild; idempotent on upgrade.
            $factory->create(ScheduledJobsInstaller::class)->run();
        });
    }
}

