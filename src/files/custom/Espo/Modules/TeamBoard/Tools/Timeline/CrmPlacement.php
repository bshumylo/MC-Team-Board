<?php

namespace Espo\Modules\TeamBoard\Tools\Timeline;

use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Read-only projection of one CRM person's actual or planned placement. */
class CrmPlacement
{
    /**
     * Prefix of the synthetic id given to an unsaved snapshot (see
     * {@see self::snapshot()}). `isVirtualSnapshot` is not a declared
     * TeamBoardAssignment attribute, so `Entity::get('isVirtualSnapshot')`
     * is silently dropped by the ORM (`BaseEntity::set()` no-ops for an
     * attribute the schema does not know) and always reads back null —
     * callers must key off this id prefix instead, never that attribute.
     */
    public const SNAPSHOT_ID_PREFIX = 'snapshot-';

    public function __construct(
        private EntityManager $entityManager,
        private Registry $registry,
        private AssignmentQuery $query,
    ) {}

    /** Whether an assignment id belongs to an unsaved, non-editable snapshot. */
    public static function isSnapshotId(string $id): bool
    {
        return str_starts_with($id, self::SNAPSHOT_ID_PREFIX);
    }

    /**
     * Null means absent on this date. A result without a team means Reserve.
     * Snapshot entities are never saved. Their synthetic ids are not editable.
     * @param Entity[] $periods
     */
    public function forUser(User $user, Entity $member, array $periods, string $date, string $today): ?Entity
    {
        if ($date < $today) {
            $fact = $this->newest(array_filter($periods, fn (Entity $period) =>
                $period->get('status') === Status::CONFIRMED && $period->get('appliedAt') !== null &&
                FactualPeriod::covers($period, $date)));
            return $fact && $this->wasActive($fact) ? $fact : null;
        }
        if (!$user->isActive()) {
            return null;
        }

        $team = null;
        $teamId = $user->get('defaultTeamId');
        if (is_string($teamId) && $teamId !== '') {
            $team = $this->entityManager->getRDBRepositoryByClass(Team::class)->getById($teamId);
            if ($team && !$this->entityManager->getRelation($user, User::LINK_TEAMS)->isRelated($team)) {
                $team = null;
            }
        }
        $position = Position::MEMBER;
        if ($team) {
            $role = $this->entityManager->getRelation($user, User::LINK_TEAMS)->getColumn($team, 'role');
            $position = is_string($role) && $role !== ''
                ? $role : Position::bottomOf(Position::listFor($team->get('positionList')));
        }
        $current = $this->newest(array_filter($periods, fn (Entity $period) =>
            $period->get('status') === Status::CONFIRMED && $period->get('appliedAt') !== null &&
            $this->wasActive($period) && FactualPeriod::covers($period, $today) &&
            $period->get('teamId') === $team?->getId() && $period->get('position') === $position));
        // Section 1, T16, T20: without a CRM default, an effective confirmed
        // board-only assignment places the user in that board-only team.
        $boardOnly = $team ? null : $this->newest(array_filter($periods, fn (Entity $period) =>
            $period->get('status') === Status::CONFIRMED && $period->get('appliedAt') !== null &&
            $this->wasActive($period) && FactualPeriod::covers($period, $today) &&
            $period->get('teamId') === null && self::isBoardOnlyTarget($period)));
        $current = $boardOnly ?? $current;

        if ($date > $today) {
            $plan = $this->newest(array_filter($periods, static fn (Entity $period) =>
                $period->get('status') === Status::CONFIRMED &&
                $period->get('dateFrom') > $today && $period->get('dateFrom') <= $date &&
                (is_string($period->get('teamId')) || self::isBoardOnlyTarget($period))));
            if ($plan) {
                if (!$this->covers($plan, $date)) {
                    return $this->snapshot($user, $member, null, Position::MEMBER, null, $plan->get('dateTo'), $today);
                }
                return $plan;
            }
            // An explicit planned exit also applies when there is no successor.
            if ($current && is_string($current->get('dateTo')) && $current->get('dateTo') <= $date &&
                !$this->query->hasPendingReplacement($current, $today)) {
                return $this->snapshot($user, $member, null, Position::MEMBER, null, $current->get('dateTo'), $today);
            }
        }

        if ($boardOnly) {
            return $boardOnly;
        }

        return $this->snapshot($user, $member, $team, $position, $current, $today, $today);
    }

    private static function isBoardOnlyTarget(Entity $period): bool
    {
        return $period->get('teamId') === null && is_string($period->get('boardSquadId')) &&
            $period->get('boardSquadId') !== '';
    }

    private function snapshot(
        User $user, Entity $member, ?Team $team, string $position, ?Entity $source, string $start, string $today
    ): Entity {
        $snapshot = $this->entityManager->getNewEntity(AssignmentQuery::ENTITY_TYPE);
        $snapshot->set([
            'id' => $source?->getId() ?? self::SNAPSHOT_ID_PREFIX . $user->getId(),
            'memberId' => $user->getId(), 'boardMemberId' => $member->getId(),
            'teamId' => $team?->getId(),
            'boardSquadId' => $team ? $this->registry->squadForTeam($team->getId())->getId() : null,
            'position' => $position, 'status' => Status::CONFIRMED,
            'dateFrom' => $source ? FactualPeriod::start($source) : $start,
            'dateTo' => $source ? FactualPeriod::displayEnd($source, $today) : null,
            'appliedAt' => $source?->get('appliedAt'), 'isVirtualSnapshot' => $source === null,
            'isCrmState' => true, 'crmIsActive' => true,
        ]);
        return $snapshot;
    }

    /** @param Entity[] $periods */
    private function newest(array $periods): ?Entity
    {
        usort($periods, static fn (Entity $a, Entity $b) =>
            [FactualPeriod::displayStart($b), $b->getId()] <=> [FactualPeriod::displayStart($a), $a->getId()]);
        return $periods[0] ?? null;
    }

    private function wasActive(Entity $period): bool
    {
        return !$period->get('isCrmState') || (bool) $period->get('crmIsActive');
    }

    private function covers(Entity $period, string $date): bool
    {
        return Interval::of((string) $period->get('dateFrom'), $period->get('dateTo'))->covers($date);
    }
}
