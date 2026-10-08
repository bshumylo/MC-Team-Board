<?php

namespace Espo\Modules\TeamBoard\Tools\Shadow;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Entities\TeamBoardMember;
use Espo\Modules\TeamBoard\Entities\TeamBoardSquad;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;

class Backfill
{
    private const ASSIGNMENT = 'TeamBoardAssignment';

    public function __construct(
        private EntityManager $entityManager,
        private Registry $registry,
    ) {}

    public function run(): BackfillReport
    {
        return $this->entityManager
            ->getTransactionManager()
            ->run(function (): BackfillReport {
                $assignments = $this->entityManager
                    ->getRDBRepository(self::ASSIGNMENT)
                    ->find();
                $assignmentCount = 0;

                foreach ($assignments as $assignment) {
                    $this->mapAssignment($assignment);
                    $assignmentCount++;
                }

                $this->mapEligibleUsers();
                $this->mapTeams();

                return new BackfillReport(
                    $this->entityManager->getRDBRepository(TeamBoardMember::ENTITY_TYPE)->count(),
                    $this->entityManager->getRDBRepository(TeamBoardSquad::ENTITY_TYPE)->count(),
                    $assignmentCount,
                );
            });
    }

    private function mapAssignment(Entity $assignment): void
    {
        $boardMemberId = $assignment->get('boardMemberId');
        $boardSquadId = $assignment->get('boardSquadId');
        $memberId = $assignment->get('memberId');
        $teamId = $assignment->get('teamId');
        $unassignedCrmFact = $assignment->get('isCrmState') && $assignment->get('appliedAt') !== null &&
            $teamId === null && $boardSquadId === null;

        if (!$boardMemberId) {
            if (!is_string($memberId) || $memberId === '') {
                throw new RuntimeException(
                    "Assignment {$assignment->getId()} has no board member or legacy User."
                );
            }

            if (!$this->entityManager->getRDBRepositoryByClass(User::class)->getById($memberId)) {
                throw new RuntimeException(
                    "Assignment {$assignment->getId()} references missing User {$memberId}."
                );
            }
        }

        if (!$boardSquadId && !$unassignedCrmFact) {
            if (!is_string($teamId) || $teamId === '') {
                throw new RuntimeException(
                    "Assignment {$assignment->getId()} has no board squad or legacy Team."
                );
            }

            if (!$this->entityManager->getRDBRepositoryByClass(Team::class)->getById($teamId)) {
                throw new RuntimeException(
                    "Assignment {$assignment->getId()} references missing Team {$teamId}."
                );
            }
        }

        if (!$boardMemberId) {
            $assignment->set(
                'boardMemberId',
                $this->registry->memberForUser((string) $memberId, true)->getId()
            );
        }

        if (!$boardSquadId && !$unassignedCrmFact) {
            $assignment->set(
                'boardSquadId',
                $this->registry->squadForTeam((string) $teamId, true)->getId()
            );
        }

        if ($assignment->isAttributeChanged('boardMemberId') ||
            $assignment->isAttributeChanged('boardSquadId')) {
            $this->entityManager->saveEntity($assignment, [SaveOption::SKIP_HOOKS => true]);
        }
    }

    private function mapEligibleUsers(): void
    {
        $users = $this->entityManager
            ->getRDBRepositoryByClass(User::class)
            ->where([
                'isActive' => true,
                'type' => [User::TYPE_REGULAR, User::TYPE_ADMIN],
            ])
            ->find();

        foreach ($users as $user) {
            $this->registry->memberForUser($user->getId(), true);
        }
    }

    private function mapTeams(): void
    {
        $teams = $this->entityManager
            ->getRDBRepositoryByClass(Team::class)
            ->find();

        foreach ($teams as $team) {
            $this->registry->squadForTeam($team->getId(), true);
        }
    }
}
