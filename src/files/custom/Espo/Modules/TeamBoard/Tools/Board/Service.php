<?php

namespace Espo\Modules\TeamBoard\Tools\Board;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\EntityProvider;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\DateTime;
use Espo\Core\Utils\Language;
use Espo\Modules\TeamBoard\Tools\Support\LocalizedDenial;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Timeline\CrmChangeHandler;
use Espo\Modules\TeamBoard\Tools\Timeline\CrmStateRecorder;
use Espo\Modules\TeamBoard\Tools\Timeline\FuturePlanResetter;
use Espo\Modules\TeamBoard\Tools\Timeline\Service as TimelineService;
use Espo\ORM\EntityManager;
use PDO;
use stdClass;

class Service
{
    use LocalizedDenial;

    private const MANAGE_SCOPE = 'TeamBoardAssignment';
    private const TEAM_USER_ENTITY = 'TeamUser';

    public function __construct(
        private Acl $acl,
        /** @phpstan-ignore property.onlyWritten */
        private User $user,
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private EntityProvider $entityProvider,
        private TimelineService $timelineService,
        private DateTime $dateTime,
        private CrmChangeHandler $crmChangeHandler,
        private CrmStateRecorder $crmStateRecorder,
        private Language $language,
    ) {}

    /**
     * Get board data. Teams and users are filtered according to the ACL
     * of the requesting user (same rules as for the Teams entity).
     *
     * @throws Forbidden
     */
    public function getData(): stdClass
    {
        $today = $this->dateTime->getToday()->toString();
        $reference = new \DateTimeImmutable($today);
        $timeline = $this->timelineService->getTimeline(
            $today,
            $reference->sub(new \DateInterval('P6M'))->format('Y-m-d'),
            $reference->add(new \DateInterval('P6M'))->format('Y-m-d'),
        );
        $unique = [];

        foreach ($timeline->teams as $team) {
            foreach ($team->members as $member) {
                $unique[$member->boardMemberId ?? $member->id] = true;
            }
        }

        foreach ($timeline->reserve as $member) {
            $unique[$member->boardMemberId ?? $member->id] = true;
        }

        $timeline->totalUnique = count($unique);
        $timeline->freeUsers = $timeline->reserve;

        return $timeline;
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
        string $position,
        bool $planned = false
    ): stdClass {

        if (!$this->user->isAdmin() &&
            (!$this->acl->checkScope('TeamBoard', Table::ACTION_READ) ||
                !$this->acl->checkScope(self::MANAGE_SCOPE, Table::ACTION_EDIT))) {
            throw $this->forbidden('noAccess');
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
            throw $this->forbidden('noEditUser');
        }

        // Changing a team's membership/positions modifies the team's roster
        // and can grant the moved user team-level record access, so it must
        // require EDIT access to the team — not merely read. Read-only team
        // access must never be enough to add or move a member.
        if (
            !$this->acl->checkEntityEdit($team) ||
            ($fromTeam && !$this->acl->checkEntityEdit($fromTeam))
        ) {
            throw $this->forbidden('noEditTeam');
        }

        // Only the scheduled job sets this internal flag. HTTP move requests
        // retain the normal CRM hooks that invalidate old future approvals.
        $writeOptions = $planned ? [FuturePlanResetter::SKIP_OPTION => true] : [];

        $demotedIds = [];

        $this->entityManager
            ->getTransactionManager()
            ->run(function () use (
                $user, $team, $fromTeam, $position, $positionList, $writeOptions, &$demotedIds
            ): void {
                $relation = $this->entityManager->getRelation($team, 'users');

                if ($relation->isRelated($user)) {
                    $relation->updateColumns($user, ['role' => $position]);
                }
                else {
                    $relation->relate($user, ['role' => $position], $writeOptions);
                }

                // Positions 1-3 except the last one are exclusive (one user
                // per team, T08); positions from the 4th and the last are shared.
                if (Position::isExclusive($positionList, $position)) {
                    $demotedIds = $this->demoteOthers(
                        $team->getId(),
                        $user->getId(),
                        $position,
                        Position::bottomOf($positionList)
                    );
                }

                if ($fromTeam) {
                    $this->entityManager
                        ->getRelation($fromTeam, 'users')
                        ->unrelate($user, $writeOptions);
                }

                // A confirmed board move establishes the destination as the
                // CRM user's default team. Keep this in the same transaction
                // as the membership change so the two values cannot diverge.
                $user->set([
                    'defaultTeamId' => $team->getId(),
                    'defaultTeamName' => $team->get('name'),
                ]);
                $this->entityManager->saveEntity($user, $writeOptions);

                if ($writeOptions !== []) {
                    // The scheduled job records the applied plan itself; the
                    // displaced holder already has its split from confirmation.
                    return;
                }

                // A position change touches only relationship columns, which
                // fire no hooks. Record the new CRM facts, so a later plan for
                // this position displaces the actual holder (T08).
                $this->crmChangeHandler->handle($user->getId());

                foreach ($demotedIds as $demotedId) {
                    $this->crmStateRecorder->recordForUser($demotedId);
                }
            });

        return $this->getData();
    }

    /**
     * Remove a user from a team (drag-out on the board).
     *
     * This is the legacy CRM-user-only path, used only when the member has
     * no shadow assignment for the viewed date (a plain live CRM
     * membership). It performs a real, present-tense CRM unrelate, so it is
     * only ever safe for the *current* day — the same "an actual period
     * cannot end in the past" rule Timeline\Service enforces for the
     * assignment-based path (PUT TeamBoard/assignment/{id}), and
     * symmetrically, a future date must be scheduled rather than acted on
     * now. $viewDate is the board date the removal was requested from; a
     * missing value is treated as today (direct/system-context callers),
     * but the HTTP action always supplies it from the client's as-of date.
     *
     * Only unlinks the user from the team. The user record itself
     * is never deleted.
     *
     * Returns fresh board data.
     *
     * @throws Forbidden
     * @throws BadRequest
     */
    public function removeMember(string $userId, string $teamId, ?string $viewDate = null): stdClass
    {
        if (!$this->user->isAdmin() &&
            (!$this->acl->checkScope('TeamBoard', Table::ACTION_READ) ||
                !$this->acl->checkScope(self::MANAGE_SCOPE, Table::ACTION_EDIT))) {
            throw $this->forbidden('noAccess');
        }

        $today = $this->dateTime->getToday()->toString();
        $effectiveDate = $viewDate ?? $today;

        if ($effectiveDate < $today) {
            // Mirrors Timeline\Service::updateAssignment's actual-period
            // rule: never let a past board view perform a real CRM change.
            throw $this->forbidden('actualEndPast');
        }

        if ($effectiveDate > $today) {
            // No shadow assignment exists to schedule this against, so a
            // future-dated removal cannot be honored as a plan — reject it
            // rather than silently unrelating the CRM membership now.
            throw $this->forbidden('removeFuture');
        }

        $user = $this->entityProvider->getByClass(User::class, $userId);
        $team = $this->entityProvider->getByClass(Team::class, $teamId);

        if (!$this->acl->checkEntityEdit($user)) {
            throw $this->forbidden('noEditUser');
        }

        // Removing a member changes the team's roster — require EDIT access
        // to the team, consistent with move().
        if (!$this->acl->checkEntityEdit($team)) {
            throw $this->forbidden('noEditTeam');
        }

        $this->entityManager
            ->getRelation($team, 'users')
            ->unrelate($user);

        // Section 8: ending a period without a next transition clears the
        // default together with the membership of that period.
        if ($user->get('defaultTeamId') === $team->getId()) {
            $user->set('defaultTeamId', null);
            $this->entityManager->saveEntity($user);
        }

        return $this->getData();
    }

    /**
     * @return array<int, Team>
     * @phpstan-ignore method.unused
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
     * @param array<int, string> $teamIds
     * @return array<string, array<string, ?string>>
     * @phpstan-ignore method.unused
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
     * @phpstan-ignore method.unused
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
     * @return array<int, stdClass>
     * @phpstan-ignore method.unused
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
     * Only one user per team can hold an exclusive position.
     * Demotes other holders to the bottom position and returns their ids.
     *
     * @return string[]
     */
    private function demoteOthers(
        string $teamId,
        string $userId,
        string $position,
        string $demoteTo
    ): array {

        $holders = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from(self::TEAM_USER_ENTITY)
            ->select(['userId'])
            ->where([
                'teamId' => $teamId,
                'role' => $position,
                'userId!=' => $userId,
                'deleted' => false,
            ])
            ->build();

        $demotedIds = array_values(array_map(
            static fn ($row) => (string) $row['userId'],
            $this->entityManager->getQueryExecutor()->execute($holders)->fetchAll(PDO::FETCH_ASSOC)
        ));

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

        return $demotedIds;
    }
}
