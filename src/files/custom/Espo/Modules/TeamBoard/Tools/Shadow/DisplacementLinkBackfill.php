<?php

namespace Espo\Modules\TeamBoard\Tools\Shadow;

use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\ORM\EntityManager;

/**
 * O05 — gives pre-existing displacement tails the back-reference they were
 * created without.
 *
 * A tail is the record a promotion leaves behind for the person it demoted.
 * Older packages recorded that relationship only forwards, in the displacing
 * plan's `displacementEffects`, so a tail was indistinguishable from a plan
 * its person had made themselves and could be reset by an unrelated manual
 * CRM change. This walks the surviving forward records and writes the missing
 * `displacedById` on each split tail.
 *
 * Idempotent: a tail that already carries the right owner is skipped, and one
 * that carries a different owner is left alone, because a later edit owns it.
 */
class DisplacementLinkBackfill
{
    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function run(): int
    {
        $linked = 0;

        $plans = $this->entityManager
            ->getRDBRepository(AssignmentQuery::ENTITY_TYPE)
            ->where(['displacementEffects!=' => null])
            ->find();

        foreach ($plans as $plan) {
            $effects = $plan->get('displacementEffects');

            if (!is_array($effects)) {
                continue;
            }

            foreach ($effects as $effect) {
                $effect = (array) $effect;

                if (($effect['mode'] ?? null) !== 'split') {
                    continue;
                }

                $tailId = $effect['tailId'] ?? null;

                if (!is_string($tailId) || $tailId === '') {
                    continue;
                }

                $tail = $this->entityManager
                    ->getEntityById(AssignmentQuery::ENTITY_TYPE, $tailId);

                if (!$tail || $tail->get('displacedById') !== null) {
                    continue;
                }

                $tail->set('displacedById', $plan->getId());
                $this->entityManager->saveEntity($tail);
                $linked++;
            }
        }

        return $linked;
    }
}
