<?php

namespace Espo\Modules\TeamBoard\Tools\Timeline;

use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class FuturePlanResetter
{
    // Internal ORM option, never populated from an API request payload.
    public const SKIP_OPTION = 'teamBoardSkipFuturePlanReset';

    public function __construct(
        private EntityManager $entityManager,
        private AssignmentQuery $query,
        private Registry $registry,
        private AssignmentEditor $editor,
        private DraftReminder $draftReminder,
    ) {}

    /**
     * T23/P12 — a manual CRM change or deactivation returns every unapplied
     * confirmed transition of this person to Draft, including tails left by
     * someone else's promotion: they are this person's transitions too, and
     * a confirmed tail would later let the job override the manual state.
     */
    public function resetForUser(string $userId): void
    {
        /** @var Entity[] $returned */
        $returned = [];

        $this->entityManager->getTransactionManager()->run(function () use ($userId, &$returned): void {
            $member = $this->registry->memberForUser($userId);
            $plans = array_values(array_filter(
                $this->query->findForMember($member->getId(), $userId),
                static fn (Entity $plan) => $plan->get('status') === Status::CONFIRMED &&
                    $plan->get('appliedAt') === null
            ));

            // Include plans whose job is late: manual CRM state must also win
            // over a pending transition due today or on an earlier day.
            // Undo a chain from its tail before restoring earlier boundaries.
            usort($plans, static fn (Entity $a, Entity $b) =>
                strcmp((string) $b->get('dateFrom'), (string) $a->get('dateFrom')));

            foreach ($plans as $plan) {
                $this->editor->returnToDraft($plan);
                $returned[] = $plan;
            }
        });

        // O02: a plan returned from confirmed to Draft inside the reminder
        // window notifies at once, not at the next job run. The reminder skips
        // dates outside the window and never repeats a recipient.
        foreach ($returned as $plan) {
            $this->draftReminder->notifyForAssignment($plan);
        }
    }
}
