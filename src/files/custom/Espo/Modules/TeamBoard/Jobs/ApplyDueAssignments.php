<?php

namespace Espo\Modules\TeamBoard\Jobs;

use Espo\Core\InjectableFactory;
use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\DateTime;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Board\Service as BoardService;
use Espo\Modules\TeamBoard\Tools\Dashboard\Reconciler;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\Modules\TeamBoard\Tools\Timeline\CrmStateRecorder;
use Espo\Modules\TeamBoard\Tools\Timeline\DraftReminder;
use Espo\Modules\TeamBoard\Tools\Timeline\FuturePlanResetter;
use Espo\Modules\TeamBoard\Tools\Timeline\Interval;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * D5 — applies confirmed assignments whose date has arrived, once each.
 *
 * The actual move goes through Board\Service::move(), preserving the one
 * implementation of team_user changes. A scheduled job has no request user,
 * so this class builds that service for an active system administrator.
 */
class ApplyDueAssignments implements JobDataLess
{
    public function __construct(
        private AssignmentQuery $query,
        private EntityManager $entityManager,
        private InjectableFactory $injectableFactory,
        private Reconciler $reconciler,
        private Log $log,
        private CrmStateRecorder $recorder,
        private DraftReminder $draftReminder,
        private DateTime $dateTime,
        private AssignmentEditor $editor,
    ) {}

    public function run(): void
    {
        $today = $this->dateTime->getToday()->toString();
        $service = $this->createBoardService();

        // Before anything else, so a restored predecessor boundary is already
        // in place when removeExpiredLinkedMemberships() scans for ended ones.
        $this->expireUnappliedPeriods($today);

        foreach ($this->query->findDue($today) as $assignment) {
            $this->apply($service, $assignment, $today);
        }

        $this->removeExpiredLinkedMemberships($today);

        $this->draftReminder->notifyUpcoming($today);
    }

    /**
     * T06, T16, T20, section 1: a confirmed assignment saved with a start of
     * today or earlier takes effect at once, through the same apply step the
     * scheduled run uses, instead of waiting for the next scheduled run.
     * Anything not due (draft, future, already applied) is left untouched.
     */
    public function applyNow(string $assignmentId, ?User $actor = null): void
    {
        $today = $this->dateTime->getToday()->toString();
        $assignment = $this->entityManager->getEntityById(AssignmentQuery::ENTITY_TYPE, $assignmentId);

        if (!$assignment || !$this->isDue($assignment, $today)) {
            return;
        }

        $this->apply($this->createBoardService($actor), $assignment, $today);
    }

    /**
     * A confirmed linked plan whose whole window elapsed before this job could
     * run can never be applied: applying it today would place the person in a
     * team the plan itself says they already left (T06, T15), and closing it
     * in the same run would be an exit to Reserve nobody requested (T07).
     *
     * It goes back to Draft, the only CRM-inert status the contract allows
     * (T04, T05) and the same state T23 uses for "an editor must look at this
     * again". That also restores the boundary the plan had closed on its
     * predecessor. T11/O02 then remove it as an overdue draft.
     */
    private function expireUnappliedPeriods(string $today): void
    {
        $expired = $this->query->findExpiredUnapplied($today);

        // Undo a chain from its tail, so each restored boundary is the one the
        // next record actually closed.
        usort(
            $expired,
            static fn (Entity $a, Entity $b) => strcmp(
                (string) $b->get('dateFrom'),
                (string) $a->get('dateFrom')
            )
        );

        foreach ($expired as $assignment) {
            try {
                $this->entityManager->getTransactionManager()->run(
                    function () use ($assignment, $today): void {
                        $fresh = $this->query->lockForUpdate($assignment->getId());

                        if (!$fresh || $fresh->get('status') !== Status::CONFIRMED ||
                            $fresh->get('appliedAt') !== null ||
                            !is_string($fresh->get('memberId')) ||
                            !is_string($fresh->get('dateTo')) || $fresh->get('dateTo') > $today) {
                            return;
                        }

                        $this->log->warning(
                            "TeamBoard: assignment {$fresh->getId()} expired unapplied — " .
                            "its window ended on {$fresh->get('dateTo')}, today is $today."
                        );

                        $this->editor->returnToDraft($fresh);
                    }
                );
            }
            catch (Throwable $e) {
                $this->log->error(
                    "TeamBoard: could not expire assignment {$assignment->getId()}. " . $e->getMessage()
                );
            }
        }
    }

    private function removeExpiredLinkedMemberships(string $today): void
    {
        foreach ($this->query->findEndedApplied($today) as $assignment) {
            if ($assignment->get('endedAt') !== null) {
                continue;
            }

            $memberId = $assignment->get('memberId');
            $teamId = $assignment->get('teamId');

            if (is_string($memberId) && $teamId === null && is_string($assignment->get('boardSquadId'))) {
                $this->endBoardOnly($assignment, $memberId, $today);

                continue;
            }

            if (!is_string($memberId) || !is_string($teamId)) {
                continue;
            }

            $member = $this->entityManager->getRDBRepositoryByClass(User::class)->getById($memberId);
            $team = $this->entityManager->getRDBRepositoryByClass(Team::class)->getById($teamId);

            if (!$member || !$team) {
                continue;
            }

            try {
                $this->entityManager->getTransactionManager()->run(
                    function () use ($assignment, $memberId, $team, $teamId, $today): void {
                        // Match the manual-change hook's lock order: User,
                        // then assignment. A dispatcher snapshot is not authority.
                        $member = $this->lockUser($memberId);
                        $assignment = $this->query->lockForUpdate($assignment->getId());
                        if (!$member || !$assignment || $assignment->get('endedAt') !== null ||
                            $assignment->get('status') !== Status::CONFIRMED ||
                            $assignment->get('appliedAt') === null ||
                            !is_string($assignment->get('dateTo')) || $assignment->get('dateTo') > $today ||
                            $assignment->get('memberId') !== $memberId || $assignment->get('teamId') !== $teamId) {
                            return;
                        }
                        // A failed replacement must leave the actual CRM team
                        // intact. Its predecessor was closed for that move,
                        // not as a separate request to remove membership.
                        if ($this->query->hasPendingReplacement($assignment, $today)) {
                            return;
                        }
                        $stillAssigned = false;
                        foreach ($this->query->findCoveringDate($today) as $current) {
                            if ($current->get('memberId') === $memberId && $current->get('teamId') === $teamId &&
                                $current->get('status') === Status::CONFIRMED && $current->get('appliedAt') !== null) {
                                $stillAssigned = true;
                                break;
                            }
                        }
                        $options = [FuturePlanResetter::SKIP_OPTION => true];
                        if (!$stillAssigned && $member->isActive()) {
                            $relation = $this->entityManager->getRelation($team, 'users');
                            if ($relation->isRelated($member)) {
                                $relation->unrelate($member, $options);
                            }

                            if ($member->get('defaultTeamId') === $teamId) {
                                $member->set(['defaultTeamId' => null, 'defaultTeamName' => null]);
                                $this->entityManager->saveEntity($member, $options);
                            }
                        }

                        // A retry must not remove membership manually added
                        // after this period was already ended.
                        $assignment->set('endedAt', date('Y-m-d H:i:s'));
                        $assignment->set('actualDateTo', $today);
                        $this->entityManager->saveEntity($assignment);
                        if (!$stillAssigned && $member->isActive()) {
                            $this->recorder->recordForUser($memberId, $today);
                        }
                    }
                );
            }
            catch (Throwable $e) {
                $this->log->error(
                    "TeamBoard: could not end assignment {$assignment->getId()}. " . $e->getMessage()
                );
            }
        }
    }

    /**
     * An applied board-only period of a CRM user reached its Until. CRM holds
     * no membership for it; without a successor the person is in Reserve.
     */
    private function endBoardOnly(Entity $assignment, string $memberId, string $today): void
    {
        try {
            $this->entityManager->getTransactionManager()->run(
                function () use ($assignment, $memberId, $today): void {
                    $member = $this->lockUser($memberId);
                    $assignment = $this->query->lockForUpdate($assignment->getId());
                    if (!$member || !$assignment || $assignment->get('endedAt') !== null ||
                        $assignment->get('status') !== Status::CONFIRMED ||
                        $assignment->get('appliedAt') === null ||
                        !is_string($assignment->get('dateTo')) || $assignment->get('dateTo') > $today ||
                        $assignment->get('memberId') !== $memberId || $assignment->get('teamId') !== null) {
                        return;
                    }
                    if ($this->query->hasPendingReplacement($assignment, $today)) {
                        return;
                    }
                    $assignment->set(['endedAt' => date('Y-m-d H:i:s'), 'actualDateTo' => $today]);
                    $this->entityManager->saveEntity($assignment);
                    if ($member->isActive()) {
                        $this->recorder->recordForUser($memberId, $today);
                    }
                }
            );
        }
        catch (Throwable $e) {
            $this->log->error(
                "TeamBoard: could not end assignment {$assignment->getId()}. " . $e->getMessage()
            );
        }
    }

    private function apply(BoardService $service, Entity $assignment, string $today): void
    {
        $memberId = $assignment->get('memberId');
        $teamId = $assignment->get('teamId');

        if (is_string($memberId) && $teamId === null && $this->isBoardOnlyTarget($assignment)) {
            $this->applyBoardOnly($assignment, $memberId, $today);

            return;
        }

        if ($memberId !== null && $teamId === null) {
            // A linked plan without any target (older packages). Never
            // translate it into removal of the user's CRM memberships.
            $this->log->warning(
                "TeamBoard: assignment {$assignment->getId()} skipped — " .
                'a CRM user cannot be assigned to a board-only team.'
            );

            return;
        }

        if ($memberId === null || $teamId === null) {
            // Pure board-only assignments have no CRM snapshot to apply.
            $this->entityManager->getTransactionManager()->run(function () use ($assignment, $today): void {
                $fresh = $this->query->lockForUpdate($assignment->getId());
                if ($fresh && $this->isDue($fresh, $today) && $fresh->get('memberId') === null) {
                    $this->markApplied($fresh);
                }
            });

            return;
        }

        if (!is_string($memberId) || !is_string($teamId)) {
            $this->log->error(
                "TeamBoard: assignment {$assignment->getId()} has invalid linked ids."
            );

            return;
        }

        $member = $this->entityManager->getRDBRepositoryByClass(User::class)->getById($memberId);
        $team = $this->entityManager->getRDBRepositoryByClass(Team::class)->getById($teamId);

        if (!$member || !$team) {
            $this->log->warning(
                "TeamBoard: assignment {$assignment->getId()} skipped — member or team is gone."
            );

            return;
        }

        if (!$member->isActive()) {
            $this->log->warning(
                "TeamBoard: assignment {$assignment->getId()} skipped — member is inactive."
            );
            return;
        }

        $dateFrom = (string) $assignment->get('dateFrom');

        if ($dateFrom < $today) {
            $this->log->warning(
                "TeamBoard: applying assignment {$assignment->getId()} late — " .
                "it was due on $dateFrom, today is $today."
            );
        }

        $applied = false;
        try {
            $this->entityManager->getTransactionManager()->run(
                function () use ($service, $assignment, $memberId, $teamId, $today, &$applied): void {
                    $member = $this->lockUser($memberId);
                    $assignment = $this->query->lockForUpdate($assignment->getId());
                    if (!$member || !$member->isActive() || !$assignment || !$this->isDue($assignment, $today) ||
                        $assignment->get('memberId') !== $memberId || $assignment->get('teamId') !== $teamId) {
                        return;
                    }
                    $defaultTeamId = $member->get('defaultTeamId');
                    $service->move(
                        $memberId,
                        $teamId,
                        is_string($defaultTeamId) && $defaultTeamId !== $teamId ? $defaultTeamId : null,
                        (string) $assignment->get('position'),
                        true
                    );
                    $this->markApplied($assignment);
                    $this->recorder->recordForUser($memberId, $today, $assignment->getId());
                    $applied = true;
                }
            );
        }
        catch (Throwable $e) {
            $this->log->error(
                "TeamBoard: could not apply assignment {$assignment->getId()}. " . $e->getMessage()
            );

            return;
        }

        // The relation hooks cover team changes; this also covers a pure
        // position change, where no relation event is emitted.
        if ($applied) {
            $this->reconciler->reconcile($memberId);
        }
    }

    /** A linked plan to an existing board-only squad (functionality.md section 1). */
    private function isBoardOnlyTarget(Entity $assignment): bool
    {
        $squadId = $assignment->get('boardSquadId');

        if (!is_string($squadId) || $squadId === '') {
            return false;
        }

        $squad = $this->entityManager->getEntityById('TeamBoardSquad', $squadId);

        return $squad !== null && !$squad->get('teamId');
    }

    /**
     * Section 1, T06, section 8: from the effective date all CRM memberships of
     * the user are removed and the default is cleared. No snapshot of the
     * previous state is kept; a return is an ordinary later assignment.
     */
    private function applyBoardOnly(Entity $assignment, string $memberId, string $today): void
    {
        $applied = false;
        try {
            $this->entityManager->getTransactionManager()->run(
                function () use ($assignment, $memberId, $today, &$applied): void {
                    $member = $this->lockUser($memberId);
                    $assignment = $this->query->lockForUpdate($assignment->getId());
                    if (!$member || !$member->isActive() || !$assignment || !$this->isDue($assignment, $today) ||
                        $assignment->get('memberId') !== $memberId || $assignment->get('teamId') !== null) {
                        return;
                    }
                    $options = [FuturePlanResetter::SKIP_OPTION => true];
                    $relation = $this->entityManager->getRelation($member, User::LINK_TEAMS);
                    $teams = iterator_to_array($relation->find());
                    foreach ($teams as $team) {
                        $relation->unrelate($team, $options);
                    }
                    if ($member->get('defaultTeamId') !== null) {
                        $member->set(['defaultTeamId' => null, 'defaultTeamName' => null]);
                        $this->entityManager->saveEntity($member, $options);
                    }
                    $this->markApplied($assignment);
                    $this->recorder->recordForUser($memberId, $today, $assignment->getId());
                    $applied = true;
                }
            );
        }
        catch (Throwable $e) {
            $this->log->error(
                "TeamBoard: could not apply assignment {$assignment->getId()}. " . $e->getMessage()
            );

            return;
        }

        if ($applied) {
            $this->reconciler->reconcile($memberId);
        }
    }

    private function lockUser(string $id): ?User
    {
        return $this->entityManager->getRDBRepositoryByClass(User::class)
            ->forUpdate()->where(['id' => $id])->findOne();
    }

    private function isDue(Entity $assignment, string $today): bool
    {
        return $assignment->get('status') === Status::CONFIRMED && $assignment->get('appliedAt') === null &&
            Interval::of((string) $assignment->get('dateFrom'), $assignment->get('dateTo'))->covers($today);
    }

    private function markApplied(Entity $assignment): void
    {
        $assignment->set('appliedAt', date('Y-m-d H:i:s'));
        $this->entityManager->saveEntity($assignment);
    }

    private function createBoardService(?User $actor = null): BoardService
    {
        if ($actor && $actor->isAdmin()) {
            return $this->injectableFactory->createWith(BoardService::class, ['user' => $actor]);
        }

        $admin = $this->entityManager
            ->getRDBRepositoryByClass(User::class)
            ->where([
                'type' => User::TYPE_ADMIN,
                'isActive' => true,
            ])
            ->order('createdAt')
            ->findOne();

        if (!$admin) {
            throw new \RuntimeException('TeamBoard: no active administrator to apply assignments as.');
        }

        return $this->injectableFactory->createWith(BoardService::class, ['user' => $admin]);
    }
}
