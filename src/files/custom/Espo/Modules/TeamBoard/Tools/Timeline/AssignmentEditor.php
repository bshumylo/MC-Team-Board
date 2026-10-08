<?php

namespace Espo\Modules\TeamBoard\Tools\Timeline;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Every write to the assignment table.
 *
 * D10 — replacing an occupied exclusive position splits the incumbent rather
 * than rewriting history. D15 — an assignment records the period it closed,
 * so deleting an unconfirmed plan can restore that period.
 */
class AssignmentEditor
{
    public const ENTITY_TYPE = 'TeamBoardAssignment';

    public function __construct(
        private EntityManager $entityManager,
        private AssignmentQuery $query,
        private Registry $registry,
    ) {}

    /**
     * @param string[] $positionList The acting team's position vocabulary.
     */
    public function create(
        string $memberId,
        string $teamId,
        string $position,
        string $dateFrom,
        ?string $dateTo,
        string $status,
        ?string $note,
        array $positionList
    ): Entity {
        $member = $this->registry->memberFromInput($memberId);
        $squad = $this->registry->squadFromInput($teamId);

        return $this->createShadow(
            $member->getId(),
            $squad->getId(),
            is_string($member->get('userId')) ? $member->get('userId') : null,
            is_string($squad->get('teamId')) ? $squad->get('teamId') : null,
            $position,
            $dateFrom,
            $dateTo,
            $status,
            $note,
            $positionList,
        );
    }

    /**
     * @param string[] $positionList The acting squad's position vocabulary.
     */
    public function createShadow(
        string $boardMemberId,
        string $boardSquadId,
        ?string $memberId,
        ?string $teamId,
        string $position,
        string $dateFrom,
        ?string $dateTo,
        string $status,
        ?string $note,
        array $positionList
    ): Entity {
        $this->assertTarget($boardSquadId, $teamId);

        $assignment = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        $this->entityManager->getTransactionManager()->run(
            function () use (
                $assignment, $boardMemberId, $boardSquadId, $memberId, $teamId, $position,
                $dateFrom, $dateTo, $status, $note, $positionList
            ): void {
                $superseded = $status === Status::CONFIRMED ? $this->findPeriodToClose(
                    $boardMemberId,
                    $boardSquadId,
                    $memberId,
                    $teamId,
                    $position,
                    $dateFrom,
                ) : null;

                $assignment->set([
                    'boardMemberId' => $boardMemberId,
                    'boardSquadId' => $boardSquadId,
                    'memberId' => $memberId,
                    'teamId' => $teamId,
                    'position' => $position,
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                    'status' => $status,
                    'note' => $note,
                    'supersedesId' => $superseded?->getId(),
                    'supersededDateTo' => $superseded?->get('dateTo'),
                ]);

                $this->entityManager->saveEntity($assignment);

                if ($superseded) {
                    $superseded->set('dateTo', $dateFrom);
                    $this->entityManager->saveEntity($superseded);
                }

                if ($status === Status::CONFIRMED) {
                    $this->assertNoIdenticalOverlap($assignment);
                }

                if (Position::isExclusive($positionList, $position)) {
                    $effects = $this->displaceIncumbents(
                        $boardSquadId,
                        $boardMemberId,
                        $teamId,
                        $memberId,
                        $position,
                        $dateFrom,
                        Position::bottomOf($positionList),
                        $status,
                        $assignment->getId(),
                        $dateTo,
                    );

                    if ($effects !== []) {
                        $assignment->set('displacementEffects', $effects);
                        $this->entityManager->saveEntity($assignment);
                    }
                }
            }
        );

        return $assignment;
    }

    public function update(
        Entity $assignment,
        string $position,
        string $teamId,
        string $dateFrom,
        ?string $dateTo,
        string $status,
        ?string $note
    ): void {
        $squad = $this->registry->squadFromInput($teamId);

        $this->updateShadow(
            $assignment,
            $position,
            $squad->getId(),
            is_string($squad->get('teamId')) ? $squad->get('teamId') : null,
            $dateFrom,
            $dateTo,
            $status,
            $note,
            $this->registry->resolveSquad($squad)->positionList,
        );
    }

    /** @param string[] $positionList */
    public function updateShadow(
        Entity $assignment,
        string $position,
        string $boardSquadId,
        ?string $teamId,
        string $dateFrom,
        ?string $dateTo,
        string $status,
        ?string $note,
        array $positionList = Position::DEFAULT_LIST
    ): void {
        $this->assertTarget($boardSquadId, $teamId);

        $this->entityManager->getTransactionManager()->run(function () use (
            $assignment, $position, $boardSquadId, $teamId, $dateFrom, $dateTo, $status, $note,
            $positionList
        ): void {
            $oldStatus = $assignment->get('status');
            $this->restoreDisplacements($assignment);
            $this->restorePredecessor($assignment);

            $memberId = (string) ($assignment->get('boardMemberId') ?: $assignment->get('memberId'));
            $superseded = $status === Status::CONFIRMED ? $this->findPeriodToClose(
                $memberId,
                $boardSquadId,
                $assignment->get('memberId'),
                $teamId,
                $position,
                $dateFrom,
                $assignment->getId(),
            ) : null;

            $assignment->set([
                'position' => $position,
                'boardSquadId' => $boardSquadId,
                'teamId' => $teamId,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'status' => $status,
                'note' => $note,
                'supersedesId' => $superseded?->getId(),
                'supersededDateTo' => $superseded?->get('dateTo'),
                'displacementEffects' => [],
            ]);
            if ($oldStatus !== $status) {
                $assignment->set('reminderUserIds', []);
            }

            $this->entityManager->saveEntity($assignment);

            if ($superseded) {
                $superseded->set('dateTo', $dateFrom);
                $this->entityManager->saveEntity($superseded);
            }

            if ($status === Status::CONFIRMED) {
                $this->assertNoIdenticalOverlap($assignment);
            }

            if (Position::isExclusive($positionList, $position)) {
                $effects = $this->displaceIncumbents(
                    $boardSquadId,
                    $memberId,
                    $teamId,
                    is_string($assignment->get('memberId')) ? $assignment->get('memberId') : null,
                    $position,
                    $dateFrom,
                    Position::bottomOf($positionList),
                    $status,
                    $assignment->getId(),
                    $dateTo,
                );

                if ($effects !== []) {
                    $assignment->set('displacementEffects', $effects);
                    $this->entityManager->saveEntity($assignment);
                }
            }
        });
    }

    /**
     * Every assignment needs a target. A CRM user may be assigned to a
     * board-only team (functionality.md section 1): the scheduled job then
     * removes all of the user's CRM memberships and clears the default.
     */
    private function assertTarget(?string $boardSquadId, ?string $teamId): void
    {
        if (($boardSquadId === null || $boardSquadId === '') && ($teamId === null || $teamId === '')) {
            throw new BadRequest('An assignment needs a team.');
        }
    }

    /**
     * Close a period at a date. The successor, when present, remembers how to
     * undo the closure.
     */
    public function closeAt(Entity $assignment, string $date, ?Entity $successor): void
    {
        if ($successor) {
            $successor->set([
                'supersedesId' => $assignment->getId(),
                'supersededDateTo' => $assignment->get('dateTo'),
            ]);

            $this->entityManager->saveEntity($successor);
        }

        $assignment->set('dateTo', $date);
        $this->entityManager->saveEntity($assignment);
    }

    /**
     * Restore the period this assignment closed, then remove the assignment.
     * If that period was removed separately, restoration is safely a no-op.
     */
    public function delete(Entity $assignment): void
    {
        $this->entityManager->getTransactionManager()->run(
            function () use ($assignment): void {
                $this->restoreDisplacements($assignment);
                $this->restorePredecessor($assignment);

                $this->entityManager->removeEntity($assignment);
            }
        );
    }

    public function returnToDraft(Entity $assignment): void
    {
        if ($assignment->get('status') !== Status::CONFIRMED || $assignment->get('appliedAt') !== null) {
            return;
        }

        $this->entityManager->getTransactionManager()->run(function () use ($assignment): void {
            $this->restoreDisplacements($assignment);
            $this->restorePredecessor($assignment);
            $assignment->set([
                'status' => Status::DRAFT,
                'supersedesId' => null,
                'supersededDateTo' => null,
                'reminderUserIds' => [],
                'displacementEffects' => [],
            ]);
            $this->entityManager->saveEntity($assignment);
        });
    }

    private function restorePredecessor(Entity $assignment): void
    {
        // Once applied, the closure is factual history rather than a plan effect.
        if ($assignment->get('appliedAt') !== null) {
            return;
        }

        $previousId = $assignment->get('supersedesId');

        if (!is_string($previousId) || $previousId === '') {
            return;
        }

        $previous = $this->entityManager->getEntityById(self::ENTITY_TYPE, $previousId);

        // A later edit owns the new boundary; an old plan must not undo it.
        if (!$previous || $previous->get('dateTo') !== $assignment->get('dateFrom')) {
            return;
        }

        $previous->set('dateTo', $assignment->get('supersededDateTo'));
        $this->entityManager->saveEntity($previous);
    }

    /**
     * Find the confirmed member period that covers the new start date; the
     * new assignment closes it there (T08). An identical team-and-position
     * period is closed too, so one person never holds two overlapping
     * confirmed copies of the same place. On the same start date the older
     * copy becomes a zero-length period: the last state of the day wins (T22).
     */
    private function findPeriodToClose(
        string $boardMemberId,
        string $boardSquadId,
        ?string $memberId,
        ?string $teamId,
        string $position,
        string $dateFrom,
        ?string $ignoreId = null
    ): ?Entity {
        foreach ($this->query->findForMember($boardMemberId, $memberId) as $existing) {
            if ($existing->get('status') !== Status::CONFIRMED) {
                continue;
            }

            if ($ignoreId !== null && $existing->getId() === $ignoreId) {
                continue;
            }

            $interval = Interval::of(
                (string) $existing->get('dateFrom'),
                $existing->get('dateTo')
            );

            if ($interval->covers($dateFrom)) {
                return $existing;
            }
        }

        return null;
    }

    /**
     * An exclusive position (Position::isExclusive(): the first three list
     * positions except the last one, T08) has one holder at a time. From the
     * new holder's start date every other confirmed holder keeps their history
     * and continues at the last list position: a covering period is split
     * into a closed part and a tail, a future period is demoted in place.
     *
     * @return array<int, array<string, mixed>>
     */
    private function displaceIncumbents(
        string $boardSquadId,
        string $newHolderId,
        ?string $teamId,
        ?string $legacyNewHolderId,
        string $position,
        string $atDate,
        string $demoteTo,
        string $newStatus,
        string $ownerId,
        ?string $untilDate = null
    ): array {
        if ($newStatus !== Status::CONFIRMED) {
            return [];
        }

        $effects = [];

        $squadWhere = [['boardSquadId' => $boardSquadId]];

        if ($teamId !== null) {
            $squadWhere[] = ['teamId' => $teamId];
        }

        $candidates = $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->where([
                'OR' => $squadWhere,
                'position' => $position,
            ])
            ->find();

        foreach ($candidates as $incumbent) {
            if ($incumbent->get('status') !== Status::CONFIRMED) {
                continue;
            }

            if ($incumbent->get('boardMemberId') === $newHolderId ||
                ($legacyNewHolderId !== null && $incumbent->get('memberId') === $legacyNewHolderId)) {
                continue;
            }

            $dateFrom = (string) $incumbent->get('dateFrom');
            $dateTo = $incumbent->get('dateTo');

            // Only the new holder's own range is contested: a plan starting
            // on or after its Until does not overlap and stays untouched.
            if ($untilDate !== null && $dateFrom >= $untilDate) {
                continue;
            }

            if ($dateFrom >= $atDate) {
                // This future record never took effect, so no historical
                // leader period needs preserving.
                $incumbent->set('position', $demoteTo);
                $this->entityManager->saveEntity($incumbent);

                $effects[] = [
                    'mode' => 'position',
                    'assignmentId' => $incumbent->getId(),
                    'originalPosition' => $position,
                    'expectedPosition' => $demoteTo,
                    'expectedDateFrom' => $dateFrom,
                    'expectedBoardSquadId' => $incumbent->get('boardSquadId'),
                    'expectedMemberId' => $incumbent->get('boardMemberId'),
                ];

                continue;
            }

            if (!Interval::of($dateFrom, $dateTo)->covers($atDate)) {
                continue;
            }

            $tail = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

            $tail->set([
                'boardMemberId' => $incumbent->get('boardMemberId'),
                'boardSquadId' => $boardSquadId,
                'memberId' => $incumbent->get('memberId'),
                'teamId' => $teamId,
                'position' => $demoteTo,
                'dateFrom' => $atDate,
                'dateTo' => $dateTo,
                'status' => $incumbent->get('status'),
                'note' => $incumbent->get('note'),
                // This tail is residue of someone else's promotion, not a plan
                // its person made. Only the plan that produced it owns it.
                'displacedById' => $ownerId,
            ]);

            $this->entityManager->saveEntity($tail);

            $incumbent->set('dateTo', $atDate);
            $this->entityManager->saveEntity($incumbent);

            $effects[] = [
                'mode' => 'split',
                'assignmentId' => $incumbent->getId(),
                'tailId' => $tail->getId(),
                'atDate' => $atDate,
                'originalDateTo' => $dateTo,
                'expectedPosition' => $demoteTo,
                'expectedBoardSquadId' => $incumbent->get('boardSquadId'),
                'expectedMemberId' => $incumbent->get('boardMemberId'),
            ];
        }

        return $effects;
    }

    /**
     * T08 — one person never holds two overlapping confirmed periods in the
     * same team and position. A covering copy has already been closed by
     * findPeriodToClose(); a later copy inside the new range is rejected.
     */
    private function assertNoIdenticalOverlap(Entity $assignment): void
    {
        $boardSquadId = $assignment->get('boardSquadId');
        $teamId = $assignment->get('teamId');
        $interval = Interval::of((string) $assignment->get('dateFrom'), $assignment->get('dateTo'));

        $existingList = $this->query->findForMember(
            (string) ($assignment->get('boardMemberId') ?: $assignment->get('memberId')),
            is_string($assignment->get('memberId')) ? $assignment->get('memberId') : null
        );

        foreach ($existingList as $existing) {
            if ($existing->getId() === $assignment->getId() ||
                $existing->get('status') !== Status::CONFIRMED ||
                $existing->get('position') !== $assignment->get('position')) {
                continue;
            }

            $sameSquad = ($boardSquadId !== null && $existing->get('boardSquadId') === $boardSquadId) ||
                ($teamId !== null && $existing->get('teamId') === $teamId);

            if (!$sameSquad) {
                continue;
            }

            $other = Interval::of((string) $existing->get('dateFrom'), $existing->get('dateTo'));

            if ($other->getDateTo() !== null && $other->getDateTo() <= $other->getDateFrom()) {
                continue;
            }

            if ($interval->overlaps($other)) {
                throw new BadRequest('The person already holds this position in this team for part of the period.');
            }
        }
    }

    /** Undo only displacement records that still exactly match this plan. */
    private function restoreDisplacements(Entity $assignment): void
    {
        if ($assignment->get('appliedAt') !== null) {
            return;
        }

        $effects = $assignment->get('displacementEffects');

        if (!is_array($effects)) {
            return;
        }

        foreach (array_reverse($effects) as $effect) {
            $effect = is_object($effect) ? get_object_vars($effect) : $effect;

            if (!is_array($effect) || !is_string($effect['assignmentId'] ?? null)) {
                continue;
            }

            $incumbent = $this->entityManager->getEntityById(
                self::ENTITY_TYPE,
                $effect['assignmentId']
            );

            if (!$incumbent) {
                continue;
            }

            if (($effect['mode'] ?? null) === 'position') {
                if ($incumbent->get('position') === ($effect['expectedPosition'] ?? null) &&
                    $incumbent->get('dateFrom') === ($effect['expectedDateFrom'] ?? null) &&
                    $incumbent->get('boardSquadId') === ($effect['expectedBoardSquadId'] ?? null) &&
                    $incumbent->get('boardMemberId') === ($effect['expectedMemberId'] ?? null)) {
                    $incumbent->set('position', $effect['originalPosition'] ?? null);
                    $this->entityManager->saveEntity($incumbent);
                    $this->reassertExclusive($incumbent, (string) ($effect['expectedPosition'] ?? ''), $assignment->getId());
                }

                continue;
            }

            if (($effect['mode'] ?? null) !== 'split' ||
                !is_string($effect['tailId'] ?? null) ||
                !is_string($effect['atDate'] ?? null)) {
                continue;
            }

            // The incumbent's own boundary is the only remaining evidence
            // once the tail is gone: if nothing else has moved it since the
            // split, undoing here is safe regardless of the tail's fate.
            if ($incumbent->get('dateTo') !== $effect['atDate']) {
                continue;
            }

            $tail = $this->entityManager->getEntityById(self::ENTITY_TYPE, $effect['tailId']);

            if ($tail) {
                // The tail still exists: only undo the split if nothing else
                // has touched it either.
                if ($tail->get('dateFrom') !== $effect['atDate'] ||
                    $tail->get('dateTo') !== ($effect['originalDateTo'] ?? null) ||
                    $tail->get('position') !== ($effect['expectedPosition'] ?? null) ||
                    $tail->get('boardSquadId') !== ($effect['expectedBoardSquadId'] ?? null) ||
                    $tail->get('boardMemberId') !== ($effect['expectedMemberId'] ?? null) ||
                    $tail->get('boardMemberId') !== $incumbent->get('boardMemberId')) {
                    continue;
                }

                $this->entityManager->removeEntity($tail);
            }

            // A tail already gone (for example removed by CleanupMissedDrafts
            // after an unrelated manual CRM change reset it to Draft) is not
            // a reason to leave the incumbent's period truncated forever:
            // there is nothing left to cross-check against, and the
            // untouched dateTo checked above is enough evidence that this
            // split's other half was never legitimately re-purposed.
            $incumbent->set('dateTo', $effect['originalDateTo'] ?? null);
            $this->entityManager->saveEntity($incumbent);
            $this->reassertExclusive($incumbent, (string) ($effect['expectedPosition'] ?? ''), $assignment->getId());
        }
    }

    /**
     * T08 — undoing one displacement must not create two confirmed holders
     * of the same exclusive position. A restored holder that now overlaps
     * another confirmed holder yields to it exactly as if that holder's plan
     * had just been made: split at its later start, or demoted when it starts
     * no later than the restored period. The effect is recorded on that
     * holder's plan, so undoing that plan restores this state consistently.
     */
    private function reassertExclusive(Entity $restored, string $demoteTo, string $undoneId): void
    {
        $position = (string) $restored->get('position');

        if ($restored->get('status') !== Status::CONFIRMED || $demoteTo === '' || $position === $demoteTo) {
            return;
        }

        $interval = Interval::of((string) $restored->get('dateFrom'), $restored->get('dateTo'));
        $squadWhere = [['boardSquadId' => $restored->get('boardSquadId')]];

        if (is_string($restored->get('teamId'))) {
            $squadWhere[] = ['teamId' => $restored->get('teamId')];
        }

        $holders = $this->entityManager->getRDBRepository(self::ENTITY_TYPE)
            ->where([
                'OR' => $squadWhere,
                'position' => $position,
                'status' => Status::CONFIRMED,
                'id!=' => [$restored->getId(), $undoneId],
            ])
            ->order('dateFrom')
            ->find();

        foreach ($holders as $holder) {
            if ($holder->get('boardMemberId') === $restored->get('boardMemberId')) {
                continue;
            }

            $other = Interval::of((string) $holder->get('dateFrom'), $holder->get('dateTo'));

            if (($other->getDateTo() !== null && $other->getDateTo() <= $other->getDateFrom()) ||
                !$interval->overlaps($other)) {
                continue;
            }

            $effects = $holder->get('displacementEffects');
            $effects = is_array($effects) ? $effects : [];
            $atDate = $other->getDateFrom();

            if ($atDate <= $interval->getDateFrom()) {
                $restored->set('position', $demoteTo);
                $this->entityManager->saveEntity($restored);

                $effects[] = [
                    'mode' => 'position',
                    'assignmentId' => $restored->getId(),
                    'originalPosition' => $position,
                    'expectedPosition' => $demoteTo,
                    'expectedDateFrom' => $restored->get('dateFrom'),
                    'expectedBoardSquadId' => $restored->get('boardSquadId'),
                    'expectedMemberId' => $restored->get('boardMemberId'),
                ];
            } else {
                $originalDateTo = $restored->get('dateTo');
                $tail = $this->entityManager->getNewEntity(self::ENTITY_TYPE);
                $tail->set([
                    'boardMemberId' => $restored->get('boardMemberId'),
                    'boardSquadId' => $restored->get('boardSquadId'),
                    'memberId' => $restored->get('memberId'),
                    'teamId' => $restored->get('teamId'),
                    'position' => $demoteTo,
                    'dateFrom' => $atDate,
                    'dateTo' => $originalDateTo,
                    'status' => Status::CONFIRMED,
                    'note' => $restored->get('note'),
                    'displacedById' => $holder->getId(),
                ]);
                $this->entityManager->saveEntity($tail);

                $restored->set('dateTo', $atDate);
                $this->entityManager->saveEntity($restored);

                $effects[] = [
                    'mode' => 'split',
                    'assignmentId' => $restored->getId(),
                    'tailId' => $tail->getId(),
                    'atDate' => $atDate,
                    'originalDateTo' => $originalDateTo,
                    'expectedPosition' => $demoteTo,
                    'expectedBoardSquadId' => $restored->get('boardSquadId'),
                    'expectedMemberId' => $restored->get('boardMemberId'),
                ];
            }

            $holder->set('displacementEffects', $effects);
            $this->entityManager->saveEntity($holder);

            return;
        }
    }
}
