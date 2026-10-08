<?php

namespace Espo\Modules\TeamBoard\Tools\Timeline;

use Espo\Core\Utils\DateTime;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Records CRM facts; it never changes a CRM profile or relationship. */
class CrmStateRecorder
{
    public function __construct(
        private EntityManager $entityManager,
        private AssignmentQuery $query,
        private Registry $registry,
        private DateTime $dateTime,
    ) {}

    public function recordForUser(string $userId, ?string $date = null, ?string $appliedId = null): void
    {
        $date ??= $this->dateTime->getToday()->toString();
        $this->entityManager->getTransactionManager()->run(function () use ($userId, $date, $appliedId): void {
            // Relationship hooks may receive an older in-memory User instance.
            $user = $this->entityManager->getRDBRepositoryByClass(User::class)->getById($userId);
            if (!$user || !in_array($user->get('type'), [User::TYPE_REGULAR, User::TYPE_ADMIN], true)) {
                return;
            }

            $member = $this->registry->memberForUser($userId);
            $state = $this->readState($user) + [
                'memberId' => $userId,
                'boardMemberId' => $member->getId(),
                'isCrmState' => true,
                'status' => Status::CONFIRMED,
            ];
            $periods = $this->query->findForMember($member->getId(), $userId);
            $facts = array_values(array_filter(
                $periods,
                static fn (Entity $period) => $period->get('status') === Status::CONFIRMED &&
                    $period->get('appliedAt') !== null && FactualPeriod::start($period) <= $date
            ));
            // Section 1: without a CRM default, an effective board-only
            // assignment of this user is the actual placement, not Reserve.
            // Only a default set in CRM (T22) or deactivation ends it here.
            if ($state['teamId'] === null && $state['crmIsActive']) {
                foreach ($facts as $period) {
                    $isApplied = $appliedId !== null && $period->getId() === $appliedId;
                    if (($isApplied || ($appliedId === null && $this->covers($period, $date))) &&
                        $period->get('teamId') === null && is_string($period->get('boardSquadId')) &&
                        $period->get('boardSquadId') !== '') {
                        $state['boardSquadId'] = $period->get('boardSquadId');
                        $state['position'] = (string) $period->get('position');
                        break;
                    }
                }
            }

            $keep = null;
            if ($appliedId !== null) {
                foreach ($facts as $period) {
                    if ($period->getId() === $appliedId && $this->covers($period, $date) &&
                        $period->get('teamId') === $state['teamId'] && $state['crmIsActive']) {
                        $keep = $period;
                        break;
                    }
                }
                if (!$keep) {
                    throw new \RuntimeException('TeamBoard: applied transition does not match the actual CRM state.');
                }
                // Keep this plan's identity and planned Until: later confirmed
                // transitions may refer to it as their predecessor.
                $keep->set($state);
                $keep->set(['actualDateFrom' => $date, 'actualDateTo' => null]);
                $this->entityManager->saveEntity($keep);
            }
            foreach ($facts as $period) {
                if ($appliedId === null && FactualPeriod::start($period) === $date) {
                    $keep = $period;
                }
            }
            if (!$keep) {
                foreach ($facts as $period) {
                    if ($this->covers($period, $date) && $this->matches($period, $state)) {
                        $keep = $period;
                        break;
                    }
                }
            }

            $now = date('Y-m-d H:i:s');
            foreach ($facts as $period) {
                if ($period === $keep) {
                    continue;
                }

                if (FactualPeriod::start($period) === $date) {
                    // Only the final factual state of this day is retained.
                    foreach ($periods as $dependent) {
                        if ($dependent->get('supersedesId') !== $period->getId()) {
                            continue;
                        }
                        $dependent->set('supersedesId', $dependent === $keep ? null : $keep?->getId());
                        if ($dependent === $keep) {
                            $dependent->set('supersededDateTo', null);
                        }
                        $this->entityManager->saveEntity($dependent);
                    }
                    $this->entityManager->removeEntity($period);
                    continue;
                }

                if ($this->covers($period, $date)) {
                    $period->set(['actualDateTo' => $date, 'endedAt' => $now]);
                    // Retain an already scheduled boundary as the plan date.
                    if ($period->get('dateTo') === null || $period->get('dateTo') > $date) {
                        $period->set('dateTo', $date);
                    }
                    $this->entityManager->saveEntity($period);
                } elseif ($period->get('endedAt') === null) {
                    // CRM now owns membership. An old, unprocessed Until must
                    // not later remove a membership that CRM intentionally kept.
                    $period->set('endedAt', $now);
                    $this->entityManager->saveEntity($period);
                }
            }

            if ($keep && $this->matches($keep, $state) && $this->covers($keep, $date)) {
                return;
            }

            $record = $keep ?? $this->entityManager->getNewEntity(AssignmentQuery::ENTITY_TYPE);
            $record->set($state + [
                'dateFrom' => $date,
                'dateTo' => null,
                'actualDateFrom' => $date,
                'actualDateTo' => null,
                'appliedAt' => $now,
                'endedAt' => null,
                'supersedesId' => null,
                'supersededDateTo' => null,
            ]);
            $this->entityManager->saveEntity($record);
        });
    }

    /** @return array{teamId: ?string, boardSquadId: ?string, position: string, crmIsActive: bool} */
    private function readState(User $user): array
    {
        $state = [
            'teamId' => null,
            'boardSquadId' => null,
            'position' => Position::MEMBER,
            'crmIsActive' => $user->isActive(),
        ];
        $teamId = $user->get('defaultTeamId');
        if (!$user->isActive() || !is_string($teamId) || $teamId === '') {
            return $state;
        }

        $team = $this->entityManager->getRDBRepositoryByClass(Team::class)->getById($teamId);
        if (!$team) {
            return $state;
        }
        $relation = $this->entityManager->getRelation($user, User::LINK_TEAMS);
        if (!$relation->isRelated($team)) {
            return $state;
        }

        $position = $relation->getColumn($team, 'role');
        $state['teamId'] = $teamId;
        $state['boardSquadId'] = $this->registry->squadForTeam($teamId)->getId();
        $state['position'] = is_string($position) && $position !== ''
            ? $position
            : Position::bottomOf(Position::listFor($team->get('positionList')));
        return $state;
    }

    /** @param array<string, mixed> $state */
    private function matches(Entity $period, array $state): bool
    {
        $isActive = $period->get('isCrmState') ? (bool) $period->get('crmIsActive') : true;
        return $period->get('teamId') === $state['teamId'] &&
            $period->get('boardSquadId') === $state['boardSquadId'] &&
            $period->get('position') === $state['position'] && $isActive === $state['crmIsActive'];
    }

    private function covers(Entity $period, string $date): bool
    {
        return FactualPeriod::covers($period, $date);
    }
}
