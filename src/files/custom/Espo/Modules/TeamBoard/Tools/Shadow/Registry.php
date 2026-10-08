<?php

namespace Espo\Modules\TeamBoard\Tools\Shadow;

use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Entities\TeamBoardMember;
use Espo\Modules\TeamBoard\Entities\TeamBoardSquad;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDOException;
use stdClass;

class Registry
{
    public function __construct(private EntityManager $entityManager) {}

    public function memberForUser(string $userId, bool $skipHooks = false): Entity
    {
        $user = $this->entityManager
            ->getRDBRepositoryByClass(User::class)
            ->getById($userId);

        if (!$user) {
            throw new NotFound("User {$userId} not found.");
        }

        $existing = $this->findMemberByUser($userId);

        if ($existing) {
            return $existing;
        }

        try {
            return $this->entityManager->createEntity(TeamBoardMember::ENTITY_TYPE, [
                'userId' => $userId,
                'name' => null,
                'photoId' => null,
                'isArchived' => false,
            ], $skipHooks ? [SaveOption::SKIP_HOOKS => true] : []);
        }
        catch (PDOException $e) {
            if ((int) $e->getCode() !== 23000) {
                throw $e;
            }

            return $this->findMemberByUser($userId) ?? throw $e;
        }
    }

    public function memberFromInput(string $id): Entity
    {
        $member = $this->entityManager->getEntityById(TeamBoardMember::ENTITY_TYPE, $id);

        return $member ?? $this->memberForUser($id);
    }

    public function squadForTeam(string $teamId, bool $skipHooks = false): Entity
    {
        $team = $this->entityManager
            ->getRDBRepositoryByClass(Team::class)
            ->getById($teamId);

        if (!$team) {
            throw new NotFound("Team {$teamId} not found.");
        }

        $existing = $this->findSquadByTeam($teamId);

        if ($existing) {
            return $existing;
        }

        try {
            return $this->entityManager->createEntity(TeamBoardSquad::ENTITY_TYPE, [
                'teamId' => $teamId,
                'name' => null,
                'colourKey' => TeamBoardSquad::COLOUR_SLATE,
                'positionList' => null,
                'isArchived' => false,
            ], $skipHooks ? [SaveOption::SKIP_HOOKS => true] : []);
        }
        catch (PDOException $e) {
            if ((int) $e->getCode() !== 23000) {
                throw $e;
            }

            return $this->findSquadByTeam($teamId) ?? throw $e;
        }
    }

    public function squadFromInput(string $id): Entity
    {
        $squad = $this->entityManager->getEntityById(TeamBoardSquad::ENTITY_TYPE, $id);

        return $squad ?? $this->squadForTeam($id);
    }

    public function resolveMember(Entity $member): stdClass
    {
        $userId = $member->get('userId');
        $boardPhotoId = $member->get('photoId');

        if (is_string($userId) && $userId !== '') {
            $user = $this->entityManager
                ->getRDBRepositoryByClass(User::class)
                ->getById($userId);

            if ($user) {
                $systemAvatarId = $user->get('avatarId');

                return (object) [
                    'boardMemberId' => $member->getId(),
                    'userId' => $user->getId(),
                    'isEspoUser' => true,
                    'name' => $user->getName() ?? $user->getUserName(),
                    'firstName' => $user->get('firstName'),
                    'lastName' => $user->get('lastName'),
                    'isActive' => (bool) $user->get('isActive'),
                    'systemAvatarId' => $systemAvatarId,
                    'boardPhotoId' => $boardPhotoId,
                    'avatarId' => $systemAvatarId ?: $boardPhotoId,
                    'avatarColor' => $user->get('avatarColor'),
                    'isArchived' => false,
                ];
            }
        }

        return (object) [
            'boardMemberId' => $member->getId(),
            'userId' => null,
            'isEspoUser' => false,
            'name' => (string) ($member->get('name') ?? ''),
            'firstName' => $member->get('firstName'),
            'lastName' => $member->get('lastName'),
            'isActive' => !(bool) $member->get('isArchived'),
            'systemAvatarId' => null,
            'boardPhotoId' => $boardPhotoId,
            'avatarId' => $boardPhotoId,
            'avatarColor' => null,
            'isArchived' => (bool) $member->get('isArchived'),
        ];
    }

    public function resolveSquad(Entity $squad): stdClass
    {
        $teamId = $squad->get('teamId');

        if (is_string($teamId) && $teamId !== '') {
            $team = $this->entityManager
                ->getRDBRepositoryByClass(Team::class)
                ->getById($teamId);

            if ($team) {
                return (object) [
                    'boardSquadId' => $squad->getId(),
                    'teamId' => $team->getId(),
                    'isEspoTeam' => true,
                    'name' => (string) $team->get('name'),
                    'positionList' => Position::listFor($team->get('positionList')),
                    'colourKey' => $this->colourKey($squad),
                    'isArchived' => false,
                ];
            }
        }

        $positionList = $squad->get('positionList');

        return (object) [
            'boardSquadId' => $squad->getId(),
            'teamId' => null,
            'isEspoTeam' => false,
            'name' => (string) ($squad->get('name') ?? ''),
            'positionList' => is_array($positionList) && $positionList !== []
                ? array_values($positionList)
                : TeamBoardSquad::DEFAULT_POSITION_LIST,
            'colourKey' => $this->colourKey($squad),
            'isArchived' => (bool) $squad->get('isArchived'),
        ];
    }

    private function findMemberByUser(string $userId): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository(TeamBoardMember::ENTITY_TYPE)
            ->where(['userId' => $userId])
            ->findOne();
    }

    private function findSquadByTeam(string $teamId): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository(TeamBoardSquad::ENTITY_TYPE)
            ->where(['teamId' => $teamId])
            ->findOne();
    }

    private function colourKey(Entity $squad): string
    {
        $key = $squad->get('colourKey');

        return is_string($key) && in_array($key, TeamBoardSquad::COLOUR_LIST, true)
            ? $key
            : TeamBoardSquad::COLOUR_SLATE;
    }
}
