<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Board\Service;
use Espo\ORM\EntityManager;
use tests\integration\Core\BaseTestCase;

class BoardTest extends BaseTestCase
{
    private function getEntityManager(): EntityManager
    {
        return $this->getContainer()->getByClass(EntityManager::class);
    }

    private function createTeam(string $name): Team
    {
        /** @var Team */
        return $this->getEntityManager()->createEntity(Team::ENTITY_TYPE, [
            'name' => $name,
        ]);
    }

    private function createMember(string $userName): User
    {
        /** @var User */
        return $this->getEntityManager()->createEntity(User::ENTITY_TYPE, [
            'userName' => $userName,
            'lastName' => $userName,
            'type' => User::TYPE_REGULAR,
        ]);
    }

    private function getRole(Team $team, User $user): ?string
    {
        $em = $this->getEntityManager();

        $row = $em->getQueryBuilder()
            ->select()
            ->from('TeamUser')
            ->select(['role'])
            ->where([
                'teamId' => $team->getId(),
                'userId' => $user->getId(),
                'deleted' => false,
            ])
            ->build();

        $sth = $em->getQueryExecutor()->execute($row);

        $result = $sth->fetch(\PDO::FETCH_ASSOC);

        return $result ? $result['role'] : null;
    }

    private function createService(): Service
    {
        return $this->getInjectableFactory()->create(Service::class);
    }

    public function testGetDataReturnsTeamsAndMembers(): void
    {
        $em = $this->getEntityManager();

        $team = $this->createTeam('Lviv');
        $user = $this->createMember('lviv.leader');

        $em->getRelation($team, 'users')->relate($user, ['role' => Position::LEADER]);

        $data = $this->createService()->getData();

        $this->assertSame(Position::DEFAULT_LIST, $data->positionList);

        $teamItem = null;

        foreach ($data->teams as $item) {
            if ($item->id === $team->getId()) {
                $teamItem = $item;
            }
        }

        $this->assertNotNull($teamItem, 'Team is present on the board.');
        $this->assertCount(1, $teamItem->members);
        $this->assertSame(Position::LEADER, $teamItem->members[0]->position);

        $this->assertSame(
            Position::DEFAULT_LIST,
            $teamItem->positionList,
            'A team without an own position list gets the default one.'
        );
    }

    public function testCustomPositionList(): void
    {
        $em = $this->getEntityManager();

        /** @var Team $team */
        $team = $em->createEntity(Team::ENTITY_TYPE, [
            'name' => 'Team Custom',
            'positionList' => ['Chief', 'Deputy', 'Agent'],
        ]);

        $chief = $this->createMember('custom.chief');
        $newChief = $this->createMember('custom.new-chief');

        $em->getRelation($team, 'users')->relate($chief, ['role' => 'Chief']);
        $em->getRelation($team, 'users')->relate($newChief, ['role' => 'Agent']);

        $service = $this->createService();

        $data = $service->move(
            $newChief->getId(),
            $team->getId(),
            $team->getId(),
            'Chief'
        );

        $this->assertSame('Chief', $this->getRole($team, $newChief));

        $this->assertSame(
            'Agent',
            $this->getRole($team, $chief),
            'The previous top-position holder is demoted to the bottom position.'
        );

        $teamItem = null;

        foreach ($data->teams as $item) {
            if ($item->id === $team->getId()) {
                $teamItem = $item;
            }
        }

        $this->assertNotNull($teamItem);
        $this->assertSame(['Chief', 'Deputy', 'Agent'], $teamItem->positionList);

        // A position from the default list is not allowed for this team.
        $this->expectException(\Espo\Core\Exceptions\BadRequest::class);

        $service->move(
            $newChief->getId(),
            $team->getId(),
            $team->getId(),
            Position::LEADER
        );
    }

    public function testNonTopPositionIsNotExclusive(): void
    {
        $em = $this->getEntityManager();

        $team = $this->createTeam('Team Vices');
        $viceA = $this->createMember('vice.a');
        $viceB = $this->createMember('vice.b');

        $em->getRelation($team, 'users')->relate($viceA, ['role' => Position::VICE_LEADER]);
        $em->getRelation($team, 'users')->relate($viceB, ['role' => Position::MEMBER]);

        $this->createService()->move(
            $viceB->getId(),
            $team->getId(),
            $team->getId(),
            Position::VICE_LEADER
        );

        $this->assertSame(Position::VICE_LEADER, $this->getRole($team, $viceB));

        $this->assertSame(
            Position::VICE_LEADER,
            $this->getRole($team, $viceA),
            'A non-top position can be held by multiple users.'
        );
    }

    public function testFreeUsers(): void
    {
        $em = $this->getEntityManager();

        $team = $this->createTeam('Team Free');
        $inTeam = $this->createMember('free.in-team');
        $free = $this->createMember('free.solo');

        $em->getRelation($team, 'users')->relate($inTeam, ['role' => Position::MEMBER]);

        $data = $this->createService()->getData();

        $freeIds = array_map(fn ($item) => $item->id, $data->freeUsers);

        $this->assertContains($free->getId(), $freeIds);
        $this->assertNotContains($inTeam->getId(), $freeIds);
    }

    public function testMoveBetweenTeams(): void
    {
        $em = $this->getEntityManager();

        $teamA = $this->createTeam('Team A');
        $teamB = $this->createTeam('Team B');
        $user = $this->createMember('member.a');

        $em->getRelation($teamA, 'users')->relate($user, ['role' => Position::MEMBER]);

        $this->createService()->move(
            $user->getId(),
            $teamB->getId(),
            $teamA->getId(),
            Position::MEMBER
        );

        $this->assertNull(
            $this->getRole($teamA, $user),
            'User is removed from the source team.'
        );

        $this->assertSame(
            Position::MEMBER,
            $this->getRole($teamB, $user),
            'User is added to the target team.'
        );
    }

    public function testMoveChangesPositionWithinTeam(): void
    {
        $em = $this->getEntityManager();

        $team = $this->createTeam('Team C');
        $user = $this->createMember('member.c');

        $em->getRelation($team, 'users')->relate($user, ['role' => Position::MEMBER]);

        $this->createService()->move(
            $user->getId(),
            $team->getId(),
            $team->getId(),
            Position::VICE_LEADER
        );

        $this->assertSame(Position::VICE_LEADER, $this->getRole($team, $user));
    }

    public function testPromotingLeaderDemotesPreviousLeader(): void
    {
        $em = $this->getEntityManager();

        $team = $this->createTeam('Team D');
        $oldLeader = $this->createMember('old.leader');
        $newLeader = $this->createMember('new.leader');

        $em->getRelation($team, 'users')->relate($oldLeader, ['role' => Position::LEADER]);
        $em->getRelation($team, 'users')->relate($newLeader, ['role' => Position::MEMBER]);

        $this->createService()->move(
            $newLeader->getId(),
            $team->getId(),
            $team->getId(),
            Position::LEADER
        );

        $this->assertSame(Position::LEADER, $this->getRole($team, $newLeader));

        $this->assertSame(
            Position::MEMBER,
            $this->getRole($team, $oldLeader),
            'The previous leader is demoted to Member.'
        );
    }

    public function testSupervisorInMultipleTeams(): void
    {
        $em = $this->getEntityManager();

        $teamA = $this->createTeam('Team E');
        $teamB = $this->createTeam('Team F');
        $supervisor = $this->createMember('supervisor.x');

        $em->getRelation($teamA, 'users')->relate($supervisor, ['role' => Position::SUPERVISOR]);
        $em->getRelation($teamB, 'users')->relate($supervisor, ['role' => Position::SUPERVISOR]);

        // Moving within team A must not affect the position in team B.
        $this->createService()->move(
            $supervisor->getId(),
            $teamA->getId(),
            $teamA->getId(),
            Position::MEMBER
        );

        $this->assertSame(Position::MEMBER, $this->getRole($teamA, $supervisor));
        $this->assertSame(Position::SUPERVISOR, $this->getRole($teamB, $supervisor));
    }

    public function testBadPositionIsRejected(): void
    {
        $team = $this->createTeam('Team G');
        $user = $this->createMember('member.g');

        $this->expectException(\Espo\Core\Exceptions\BadRequest::class);

        $this->createService()->move(
            $user->getId(),
            $team->getId(),
            null,
            'CEO'
        );
    }

    public function testNoAccessWithoutAclScope(): void
    {
        $this->createUser('tester', [
            'data' => [
                'User' => ['read' => 'all'],
                'Team' => ['read' => 'all'],
            ],
        ]);

        $this->auth('tester');

        $this->expectException(Forbidden::class);

        $this->getInjectableFactory()
            ->create(Service::class)
            ->getData();
    }

    public function testAccessWithAclScope(): void
    {
        $this->createUser('tester2', [
            'data' => [
                'TeamBoard' => true,
                'User' => ['read' => 'all'],
                'Team' => ['read' => 'all'],
            ],
        ]);

        $this->auth('tester2');

        $data = $this->getInjectableFactory()
            ->create(Service::class)
            ->getData();

        $this->assertFalse($data->canManage);
    }

    public function testRemoveMember(): void
    {
        $em = $this->getEntityManager();

        $team = $this->createTeam('Team H');
        $user = $this->createMember('member.h');

        $em->getRelation($team, 'users')->relate($user, ['role' => Position::MEMBER]);

        $this->createService()->removeMember($user->getId(), $team->getId());

        $this->assertNull(
            $this->getRole($team, $user),
            'User is removed from the team.'
        );

        $freshUser = $em->getEntityById(User::ENTITY_TYPE, $user->getId());

        $this->assertNotNull(
            $freshUser,
            'The user record itself is not deleted — only the team membership.'
        );
    }

    public function testAddToSecondTeamKeepsFirstMembership(): void
    {
        $em = $this->getEntityManager();

        $teamA = $this->createTeam('Team I');
        $teamB = $this->createTeam('Team J');
        $user = $this->createMember('member.i');

        $em->getRelation($teamA, 'users')->relate($user, ['role' => Position::LEADER]);

        // fromTeamId = null → add, not move.
        $this->createService()->move(
            $user->getId(),
            $teamB->getId(),
            null,
            Position::MEMBER
        );

        $this->assertSame(
            Position::LEADER,
            $this->getRole($teamA, $user),
            'Membership and position in the first team are kept.'
        );

        $this->assertSame(Position::MEMBER, $this->getRole($teamB, $user));
    }

    public function testTotalUniqueCountsUserOnce(): void
    {
        $em = $this->getEntityManager();

        $teamA = $this->createTeam('Team K');
        $teamB = $this->createTeam('Team L');
        $user = $this->createMember('member.k');

        $em->getRelation($teamA, 'users')->relate($user, ['role' => Position::MEMBER]);
        $em->getRelation($teamB, 'users')->relate($user, ['role' => Position::MEMBER]);

        $data = $this->createService()->getData();

        $this->assertSame(1, $data->totalUnique);
    }
}
