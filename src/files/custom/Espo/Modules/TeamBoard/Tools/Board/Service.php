<?php

namespace Espo\Modules\TeamBoard\Tools\Board;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\EntityProvider;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use PDO;
use stdClass;

class Service
{
    private const SCOPE = 'TeamBoard';
    private const TEAM_USER_ENTITY = 'TeamUser';

    public function __construct(
        private Acl $acl,
        private User $user,
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private EntityProvider $entityProvider,
    ) {}

    /**
     * Get board data. Teams and users are filtered according to the ACL
     * of the requesting user (same rules as for the Teams entity).
     *
     * @throws Forbidden
     */
    public function getData(): stdClass
    {
        if (!$this->acl->check(self::SCOPE)) {
            throw new Forbidden("No access to Team Board.");
        }

        $teams = $this->findTeams();

        $teamIds = array_map(fn (Team $team) => $team->getId(), $teams);

        $positionMap = $this->getPositionMap($teamIds);

        $userMap = $this->findUsers($positionMap);

        $canManage = $this->user->isAdmin() ||
            $this->acl->checkScope(User::ENTITY_TYPE, Table::ACTION_EDIT);

        $teamList = [];

        foreach ($teams as $team) {
            $members = [];

            foreach ($positionMap[$team->getId()] ?? [] as $userId => $position) {
                $user = $userMap[$userId] ?? null;

                if (!$user) {
                    continue;
                }

                $members[] = (object) [
                    'id' => $user->getId(),
                    'name' => $user->getName() ?? $user->getUserName(),
                    'userName' => $user->getUserName(),
                    'avatarId' => $user->get('avatarId'),
                    'avatarColor' => $user->get('avatarColor'),
                    'position' => $position,
                ];
            }

            $teamList[] = (object) [
                'id' => $team->getId(),
                'name' => $team->get('name'),
                'positionList' => Position::listFor($team->get('positionList')),
                'members' => $members,
            ];
        }

        return (object) [
            'positionList' => Position::DEFAULT_LIST,
            'canManage' => $canManage,
            'totalUnique' => count($userMap),
            'teams' => $teamList,
            'freeUsers' => $canManage ? $this->findFreeUsers() : [],
        ];
    }

    /**
     * Move a user to a team (or within a team) with a given position.
     * Saved immediately; returns fresh board data.
     *
     * @throws BadRequest
     * @throws Forbidden
     */
    public function move(
        string $userId,
        string $teamId,
        ?string $fromTeamId,
        string $position
    ): stdClass {

        if (!$this->acl->check(self::SCOPE)) {
            throw new Forbidden("No access to Team Board.");
        }

        $user = $this->entityProvider->getByClass(User::class, $userId);
        $team = $this->entityProvider->getByClass(Team::class, $teamId);

        $positionList = Position::listFor($team->get('positionList'));

        if (!in_array($position, $positionList, true)) {
            throw new BadRequest("Not allowed position.");
        }

        $fromTeam = null;

        if ($fromTeamId && $fromTeamId !== $teamId) {
            $fromTeam = $this->entityProvider->getByClass(Team::class, $fromTeamId);
        }

        if (!$this->acl->checkEntityEdit($user)) {
            throw new Forbidden("No edit access to user.");
        }

        if (
            !$this->acl->checkEntityRead($team) ||
            ($fromTeam && !$this->acl->checkEntityRead($fromTeam))
        ) {
            throw new Forbidden("No access to team.");
        }

        $this->entityManager
            ->getTransactionManager()
            ->run(function () use ($user, $team, $fromTeam, $position, $positionList): void {
                $relation = $this->entityManager->getRelation($team, 'users');

                if ($relation->isRelated($user)) {
                    $relation->updateColumns($user, ['role' => $position]);
                }
                else {
                    $relation->relate($user, ['role' => $position]);
                }

                // Only the top position is exclusive (one user per team).
                if ($position === Position::topOf($positionList)) {
                    $this->demoteOthers(
                        $team->getId(),
                        $user->getId(),
                        $position,
                        Position::bottomOf($positionList)
                    );
                }

                if ($fromTeam) {
                    $this->entityManager
                        ->getRelation($fromTeam, 'users')
                        ->unrelate($user);
                }
            });

        return $this->getData();
    }

    /**
     * Remove a user from a team (drag-out on the board).
     *
     * Only unlinks the user from the team. The user record itself
     * is never deleted.
     *
     * Returns fresh board data.
     *
     * @throws Forbidden
     */
    public function removeMember(string $userId, string $teamId): stdClass
    {
        if (!$this->acl->check(self::SCOPE)) {
            throw new Forbidden("No access to Team Board.");
        }

        $user = $this->entityProvider->getByClass(User::class, $userId);
        $team = $this->entityProvider->getByClass(Team::class, $teamId);

        if (!$this->acl->checkEntityEdit($user)) {
            throw new Forbidden("No edit access to user.");
        }

        if (!$this->acl->checkEntityRead($team)) {
            throw new Forbidden("No access to team.");
        }

        $this->entityManager
            ->getRelation($team, 'users')
            ->unrelate($user);

        return $this->getData();
    }

    /**
     * @return Team[]
     */
    private function findTeams(): array
    {
        $query = $this->selectBuilderFactory
            ->create()
            ->from(Team::ENTITY_TYPE)
            ->withStrictAccessControl()
            ->buildQueryBuilder()
            ->order('name')
            ->build();

        $collection = $this->entityManager
            ->getRDBRepositoryByClass(Team::class)
            ->clone($query)
            ->find();

        $teams = [];

        foreach ($collection as $team) {
            $teams[] = $team;
        }

        return $teams;
    }

    /**
     * Map of teamId => [userId => position].
     *
     * @param string[] $teamIds
     * @return array<string, array<string, ?string>>
     */
    private function getPositionMap(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from(self::TEAM_USER_ENTITY)
            ->select(['teamId', 'userId', 'role'])
            ->where([
                'teamId' => $teamIds,
                'deleted' => false,
            ])
            ->build();

        $sth = $this->entityManager
            ->getQueryExecutor()
            ->execute($query);

        $map = [];

        foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[$row['teamId']][$row['userId']] = $row['role'];
        }

        return $map;
    }

    /**
     * Users visible to the requesting user, according to the User ACL.
     *
     * @param array<string, array<string, ?string>> $positionMap
     * @return array<string, User>
     */
    private function findUsers(array $positionMap): array
    {
        $userIds = [];

        foreach ($positionMap as $userPositions) {
            foreach (array_keys($userPositions) as $userId) {
                $userIds[$userId] = true;
            }
        }

        if ($userIds === []) {
            return [];
        }

        try {
            $query = $this->selectBuilderFactory
                ->create()
                ->from(User::ENTITY_TYPE)
                ->withStrictAccessControl()
                ->buildQueryBuilder()
                ->where([
                    'id' => array_keys($userIds),
                    'isActive' => true,
                    'type' => [User::TYPE_REGULAR, User::TYPE_ADMIN],
                ])
                ->build();
        }
        catch (Forbidden) {
            return [];
        }

        $collection = $this->entityManager
            ->getRDBRepositoryByClass(User::class)
            ->clone($query)
            ->find();

        $map = [];

        foreach ($collection as $user) {
            $map[$user->getId()] = $user;
        }

        return $map;
    }

    /**
     * Active users that are not members of any team,
     * filtered by the User ACL of the requesting user.
     *
     * @return stdClass[]
     */
    private function findFreeUsers(): array
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from(self::TEAM_USER_ENTITY)
            ->select(['userId'])
            ->where(['deleted' => false])
            ->distinct()
            ->build();

        $sth = $this->entityManager
            ->getQueryExecutor()
            ->execute($query);

        $memberIds = array_column($sth->fetchAll(PDO::FETCH_ASSOC), 'userId');

        try {
            $builder = $this->selectBuilderFactory
                ->create()
                ->from(User::ENTITY_TYPE)
                ->withStrictAccessControl()
                ->buildQueryBuilder()
                ->where([
                    'isActive' => true,
                    'type' => [User::TYPE_REGULAR, User::TYPE_ADMIN],
                ])
                ->order('name');

            if ($memberIds !== []) {
                $builder->where(['id!=' => $memberIds]);
            }

            $query = $builder->build();
        }
        catch (Forbidden) {
            return [];
        }

        $collection = $this->entityManager
            ->getRDBRepositoryByClass(User::class)
            ->clone($query)
            ->find();

        $list = [];

        foreach ($collection as $user) {
            $list[] = (object) [
                'id' => $user->getId(),
                'name' => $user->getName() ?? $user->getUserName(),
                'userName' => $user->getUserName(),
                'avatarId' => $user->get('avatarId'),
                'avatarColor' => $user->get('avatarColor'),
            ];
        }

        return $list;
    }

    /**
     * Only one user per team can hold the top position.
     * Demotes other holders to the bottom position of the team.
     */
    private function demoteOthers(
        string $teamId,
        string $userId,
        string $position,
        string $demoteTo
    ): void {

        $query = $this->entityManager
            ->getQueryBuilder()
            ->update()
            ->in(self::TEAM_USER_ENTITY)
            ->set(['role' => $demoteTo])
            ->where([
                'teamId' => $teamId,
                'role' => $position,
                'userId!=' => $userId,
                'deleted' => false,
            ])
            ->build();

        $this->entityManager
            ->getQueryExecutor()
            ->execute($query);
    }
}
