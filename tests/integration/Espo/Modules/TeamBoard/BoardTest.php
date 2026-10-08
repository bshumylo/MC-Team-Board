<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Board\Service;
use tests\integration\Core\BaseTestCase;

class BoardTest extends BaseTestCase
{
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

    private function createCurrentAssignment(Team $team, User $user, string $position): void
    {
        // Current system placement requires membership AND CRM default.
        $this->getEntityManager()->getRelation($team, 'users')->relate($user, ['role' => $position]);
        $user->set('defaultTeamId', $team->getId());
        $this->getEntityManager()->saveEntity($user);
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
        $this->createCurrentAssignment($team, $user, Position::LEADER);

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

    public function testTheBottomPositionIsShared(): void
    {
        $em = $this->getEntityManager();

        $team = $this->createTeam('Team Members');
        $memberA = $this->createMember('shared.a');
        $memberB = $this->createMember('shared.b');

        $em->getRelation($team, 'users')->relate($memberA, ['role' => Position::MEMBER]);
        $em->getRelation($team, 'users')->relate($memberB, ['role' => Position::MEMBER]);

        $this->createService()->move(
            $memberB->getId(),
            $team->getId(),
            $team->getId(),
            Position::MEMBER
        );

        $this->assertSame(Position::MEMBER, $this->getRole($team, $memberB));
        $this->assertSame(Position::MEMBER, $this->getRole($team, $memberA));
    }

    public function testEveryLeadingPositionIsExclusive(): void
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
            Position::MEMBER,
            $this->getRole($team, $viceA),
            'Two people may not hold Vice Leader at once; the previous holder continues as a member.'
        );
    }

    public function testFreeUsers(): void
    {
        $em = $this->getEntityManager();

        $team = $this->createTeam('Team Free');
        $inTeam = $this->createMember('free.in-team');
        $free = $this->createMember('free.solo');

        $em->getRelation($team, 'users')->relate($inTeam, ['role' => Position::MEMBER]);
        $this->createCurrentAssignment($team, $inTeam, Position::MEMBER);

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

    public function testSupervisorIsExclusiveOnlyWithinItsTeam(): void
    {
        // T08 (2026-09-25): the first position has one holder per team. The
        // same person may still be Supervisor of several teams.
        $em = $this->getEntityManager();

        $teamA = $this->createTeam('Team S1');
        $teamB = $this->createTeam('Team S2');
        $previous = $this->createMember('supervisor.prev');
        $next = $this->createMember('supervisor.next');

        $em->getRelation($teamA, 'users')->relate($previous, ['role' => Position::SUPERVISOR]);
        $em->getRelation($teamB, 'users')->relate($previous, ['role' => Position::SUPERVISOR]);
        $em->getRelation($teamA, 'users')->relate($next, ['role' => Position::MEMBER]);

        $this->createService()->move(
            $next->getId(),
            $teamA->getId(),
            $teamA->getId(),
            Position::SUPERVISOR
        );

        $this->assertSame(Position::SUPERVISOR, $this->getRole($teamA, $next));
        $this->assertSame(Position::MEMBER, $this->getRole($teamA, $previous));
        $this->assertSame(Position::SUPERVISOR, $this->getRole($teamB, $previous));
    }

    public function testPositionsFromTheFourthAreShared(): void
    {
        $em = $this->getEntityManager();

        /** @var Team $team */
        $team = $em->createEntity(Team::ENTITY_TYPE, [
            'name' => 'Team Five Ranks',
            'positionList' => ['Chief', 'Deputy', 'Aide', 'Senior', 'Agent'],
        ]);

        $seniorA = $this->createMember('senior.a');
        $seniorB = $this->createMember('senior.b');

        $em->getRelation($team, 'users')->relate($seniorA, ['role' => 'Senior']);
        $em->getRelation($team, 'users')->relate($seniorB, ['role' => 'Agent']);

        $this->createService()->move(
            $seniorB->getId(),
            $team->getId(),
            $team->getId(),
            'Senior'
        );

        $this->assertSame('Senior', $this->getRole($team, $seniorB));
        $this->assertSame('Senior', $this->getRole($team, $seniorA));
    }

    public function testDemotionTargetIsTheLastPositionByOrder(): void
    {
        // T08/U16: the demotion target is the last position of the list,
        // even when it is named Supervisor (review #36, list [Boss, Supervisor]).
        $em = $this->getEntityManager();

        /** @var Team $team */
        $team = $em->createEntity(Team::ENTITY_TYPE, [
            'name' => 'Team Boss Order',
            'positionList' => ['Boss', 'Supervisor'],
        ]);

        $previous = $this->createMember('boss.prev');
        $next = $this->createMember('boss.next');

        $em->getRelation($team, 'users')->relate($previous, ['role' => 'Boss']);
        $em->getRelation($team, 'users')->relate($next, ['role' => 'Supervisor']);

        $this->createService()->move($next->getId(), $team->getId(), $team->getId(), 'Boss');

        $this->assertSame('Boss', $this->getRole($team, $next));
        $this->assertSame('Supervisor', $this->getRole($team, $previous));
    }

    public function testExistingMultipleHoldersAreNotRewritten(): void
    {
        // Legacy data with two Supervisors stays as is until someone is newly
        // assigned to that position; unrelated changes do not touch it.
        $em = $this->getEntityManager();

        $team = $this->createTeam('Team Legacy');
        $supA = $this->createMember('legacy.sup.a');
        $supB = $this->createMember('legacy.sup.b');
        $member = $this->createMember('legacy.member');

        $em->getRelation($team, 'users')->relate($supA, ['role' => Position::SUPERVISOR]);
        $em->getRelation($team, 'users')->relate($supB, ['role' => Position::SUPERVISOR]);
        $em->getRelation($team, 'users')->relate($member, ['role' => Position::MEMBER]);

        $service = $this->createService();
        $service->getData();
        $service->move($member->getId(), $team->getId(), $team->getId(), Position::LEADER);

        $this->assertSame(Position::SUPERVISOR, $this->getRole($team, $supA));
        $this->assertSame(Position::SUPERVISOR, $this->getRole($team, $supB));
        $this->assertSame(Position::LEADER, $this->getRole($team, $member));
    }

    public function testLiveMoveRecordsNewAndDemotedHoldersForLaterPlans(): void
    {
        // T08: a live board move changes only relationship columns. The new
        // holder and the demoted one must be recorded, so a later confirmed
        // plan for that position displaces the actual holder only.
        $em = $this->getEntityManager();
        $factory = $this->getInjectableFactory();

        $team = $this->createTeam('Team Live Record');
        $previous = $this->createMember('live.record.prev');
        $next = $this->createMember('live.record.next');
        $later = $this->createMember('live.record.later');
        $this->createCurrentAssignment($team, $previous, Position::SUPERVISOR);
        $this->createCurrentAssignment($team, $next, Position::MEMBER);
        $this->createCurrentAssignment($team, $later, Position::MEMBER);

        $this->createService()->move($next->getId(), $team->getId(), $team->getId(), Position::SUPERVISOR);

        $future = (new \DateTimeImmutable('+20 days'))->format('Y-m-d');
        $factory->create(\Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor::class)->create(
            $later->getId(), $team->getId(), Position::SUPERVISOR, $future, null,
            \Espo\Modules\TeamBoard\Tools\Timeline\Status::CONFIRMED, null, Position::DEFAULT_LIST
        );

        $registry = $factory->create(\Espo\Modules\TeamBoard\Tools\Shadow\Registry::class);
        $query = $factory->create(\Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery::class);
        $holdersOn = function (string $date) use ($registry, $query, $previous, $next, $later): array {
            $holders = [];
            foreach (['previous' => $previous, 'next' => $next, 'later' => $later] as $name => $user) {
                $member = $registry->memberForUser($user->getId());
                foreach ($query->findForMember($member->getId(), $user->getId()) as $row) {
                    if ($row->get('status') === \Espo\Modules\TeamBoard\Tools\Timeline\Status::CONFIRMED &&
                        $row->get('position') === Position::SUPERVISOR && $row->get('dateFrom') <= $date &&
                        ($row->get('dateTo') === null || $row->get('dateTo') > $date)) {
                        $holders[] = $name;
                    }
                }
            }
            sort($holders);
            return $holders;
        };

        $this->assertSame(['next'], $holdersOn(date('Y-m-d')));
        $this->assertSame(['later'], $holdersOn($future));
        $this->assertSame(Position::MEMBER, $this->getRole($team, $previous));
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

        $this->authenticate('tester');

        $this->expectException(Forbidden::class);

        $this->getInjectableFactory()
            ->create(Service::class)
            ->getData();
    }

    public function testAccessWithAclScope(): void
    {
        $this->createUser('tester2', [
            'data' => [
                'TeamBoard' => ['read' => 'all', 'edit' => 'no'],
                'User' => ['read' => 'all'],
                'Team' => ['read' => 'all'],
            ],
        ]);

        $this->authenticate('tester2');

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

    public function testRemoveMemberAcceptsExplicitTodayViewDate(): void
    {
        $em = $this->getEntityManager();

        $team = $this->createTeam('Team H2');
        $user = $this->createMember('member.h2');

        $em->getRelation($team, 'users')->relate($user, ['role' => Position::MEMBER]);

        $today = date('Y-m-d');

        $this->createService()->removeMember($user->getId(), $team->getId(), $today);

        $this->assertNull(
            $this->getRole($team, $user),
            'User is removed from the team when viewDate is today.'
        );
    }

    public function testRemoveMemberIsForbiddenForPastViewDate(): void
    {
        $em = $this->getEntityManager();

        $team = $this->createTeam('Team H3');
        $user = $this->createMember('member.h3');

        $em->getRelation($team, 'users')->relate($user, ['role' => Position::MEMBER]);

        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $this->expectException(Forbidden::class);

        $this->createService()->removeMember($user->getId(), $team->getId(), $yesterday);
    }

    public function testRemoveMemberIsForbiddenForFutureViewDate(): void
    {
        $em = $this->getEntityManager();

        $team = $this->createTeam('Team H4');
        $user = $this->createMember('member.h4');

        $em->getRelation($team, 'users')->relate($user, ['role' => Position::MEMBER]);

        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        try {
            $this->createService()->removeMember($user->getId(), $team->getId(), $tomorrow);
            $this->fail('Expected Forbidden for a future viewDate.');
        }
        catch (Forbidden) {
            // Membership must remain untouched — no present-tense unrelate
            // performed for a future-dated removal request.
            $this->assertNotNull(
                $this->getRole($team, $user),
                'Membership is unchanged when the removal is rejected for a future date.'
            );
        }
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

    // ------------------------------------------------------------------
    // Security: team-membership mutation must require EDIT access to the
    // team, not merely read. Read-only team access permitting a move would
    // let a user add themselves (or a user they can edit) to any readable
    // team and inherit that team's record access — a privilege escalation
    // that bypasses the admin-restricted User.teams field. (SEC-ACL-01/03,
    // SEC-FLD-01)
    // ------------------------------------------------------------------

    public function testMoveIsForbiddenWithTeamReadOnly(): void
    {
        $team = $this->createTeam('Team Sec A');
        $victim = $this->createMember('sec.victim.a');

        $this->createUser('sec.reader', [
            'data' => [
                'TeamBoard' => true,
                'User' => ['read' => 'all', 'edit' => 'all'],
                'Team' => ['read' => 'all'],
            ],
        ]);

        $this->authenticate('sec.reader');

        $this->expectException(Forbidden::class);

        $this->getInjectableFactory()
            ->create(Service::class)
            ->move($victim->getId(), $team->getId(), null, Position::MEMBER);
    }

    public function testMoveStillRequiresUserEditWithTeamEdit(): void
    {
        $team = $this->createTeam('Team Sec B');
        $victim = $this->createMember('sec.victim.b');

        $this->createUser('sec.manager', [
            'data' => [
                'TeamBoard' => true,
                'User' => ['read' => 'all', 'edit' => 'all'],
                'Team' => ['read' => 'all', 'edit' => 'all'],
            ],
        ]);

        $this->authenticate('sec.manager');

        $this->expectException(Forbidden::class);

        $this->getInjectableFactory()
            ->create(Service::class)
            ->move($victim->getId(), $team->getId(), null, Position::MEMBER);
    }

    public function testRemoveMemberIsForbiddenWithTeamReadOnly(): void
    {
        $em = $this->getEntityManager();

        $team = $this->createTeam('Team Sec C');
        $victim = $this->createMember('sec.victim.c');

        $em->getRelation($team, 'users')->relate($victim, ['role' => Position::MEMBER]);

        $this->createUser('sec.reader2', [
            'data' => [
                'TeamBoard' => true,
                'User' => ['read' => 'all', 'edit' => 'all'],
                'Team' => ['read' => 'all'],
            ],
        ]);

        $this->authenticate('sec.reader2');

        $this->expectException(Forbidden::class);

        $this->getInjectableFactory()
            ->create(Service::class)
            ->removeMember($victim->getId(), $team->getId());
    }

    public function testSelfJoinToReadableTeamIsForbidden(): void
    {
        // The core escalation: a non-admin with edit access to their own
        // user + read on a team must NOT be able to add themselves to it.
        $team = $this->createTeam('Team Sec D');

        $attacker = $this->createUser('sec.selfjoin', [
            'data' => [
                'TeamBoard' => true,
                'User' => ['read' => 'own', 'edit' => 'own'],
                'Team' => ['read' => 'all'],
            ],
        ]);

        $this->authenticate('sec.selfjoin');

        $this->expectException(Forbidden::class);

        $this->getInjectableFactory()
            ->create(Service::class)
            ->move($attacker->getId(), $team->getId(), null, Position::MEMBER);
    }

    public function testRemovingAMemberFromTheDefaultTeamClearsTheDefault(): void
    {
        $em = $this->getEntityManager();
        $team = $this->createTeam('Team default removal');
        $other = $this->createTeam('Team default kept');
        $user = $this->createMember('member.default.removal');
        $em->getRelation($team, 'users')->relate($user, ['role' => Position::MEMBER]);
        $em->getRelation($other, 'users')->relate($user, ['role' => Position::MEMBER]);
        $user->set('defaultTeamId', $team->getId());
        $em->saveEntity($user);

        $this->createService()->removeMember($user->getId(), $team->getId());

        // Section 8: ending a period without a next transition clears the
        // default and removes only that membership.
        $fresh = $em->getEntityById(User::ENTITY_TYPE, $user->getId());
        $this->assertNull($fresh?->get('defaultTeamId'));
        $this->assertNull($this->getRole($team, $user));
        $this->assertNotNull($this->getRole($other, $user));
    }

    public function testRemovingAMemberFromAnotherTeamKeepsTheDefault(): void
    {
        $em = $this->getEntityManager();
        $team = $this->createTeam('Team side removal');
        $default = $this->createTeam('Team side default');
        $user = $this->createMember('member.side.removal');
        $em->getRelation($team, 'users')->relate($user, ['role' => Position::MEMBER]);
        $em->getRelation($default, 'users')->relate($user, ['role' => Position::MEMBER]);
        $user->set('defaultTeamId', $default->getId());
        $em->saveEntity($user);

        $this->createService()->removeMember($user->getId(), $team->getId());

        $this->assertSame($default->getId(), $em->getEntityById(User::ENTITY_TYPE, $user->getId())?->get('defaultTeamId'));
    }

    public function testDenialReasonIsLocalizedForUkrainianUsers(): void
    {
        $factory = $this->getInjectableFactory()->create(\Espo\Core\Utils\Language\LanguageFactory::class);

        $this->assertSame(
            'No access to Team Board.',
            $factory->create('en_US')->translate('deny_noAccess', 'messages', 'TeamBoard')
        );
        $this->assertSame(
            'У вас немає доступу до Team Board.',
            $factory->create('uk_UA')->translate('deny_noAccess', 'messages', 'TeamBoard')
        );
    }
}
