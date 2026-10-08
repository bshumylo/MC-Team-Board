<?php

namespace Espo\Modules\TeamBoard\Tools\Timeline;

use Espo\Entities\User;
use Espo\ORM\EntityManager;

class CrmChangeHandler
{
    public function __construct(
        private EntityManager $entityManager,
        private FuturePlanResetter $resetter,
        private CrmStateRecorder $recorder,
    ) {}

    public function handle(string $userId): void
    {
        $this->entityManager->getTransactionManager()->run(function () use ($userId): void {
            $user = $this->entityManager->getRDBRepositoryByClass(User::class)
                ->forUpdate()->where(['id' => $userId])->findOne();
            if (!$user) {
                return;
            }

            // Restore planned boundaries before recording the new CRM fact.
            $this->resetter->resetForUser($userId);
            $this->recorder->recordForUser($userId);
        });
    }
}
