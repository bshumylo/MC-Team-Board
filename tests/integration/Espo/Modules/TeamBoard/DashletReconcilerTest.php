<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Entities\Preferences;
use Espo\Entities\Role;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Dashboard\Reconciler;
use PDO;
use RuntimeException;
use Throwable;
use tests\integration\Core\BaseTestCase;

class DashletReconcilerTest extends BaseTestCase
{
    private const DASHLET_ID = 'd-cal';
    private const DASHLET_ID_2 = 'd-cal-2';

    private function createTeam(string $name): Team
    {
        /** @var Team */
        return $this->getEntityManager()->createEntity(Team::ENTITY_TYPE, ['name' => $name]);
    }

    private function createRole(string $name, string $calendarPermission): Role
    {
        /** @var Role */
        return $this->getEntityManager()->createEntity(Role::ENTITY_TYPE, [
            'name' => $name,
            'data' => (object) ['Calendar' => true],
            'userCalendarPermission' => $calendarPermission,
        ]);
    }

    /**
     * @param string[] $teamIds
     */
    private function createMember(string $userName, Role $role, array $teamIds): User
    {
        /** @var User */
        $user = $this->getEntityManager()->createEntity(User::ENTITY_TYPE, [
            'userName' => $userName,
            'lastName' => $userName,
            'type' => User::TYPE_REGULAR,
            'isActive' => true,
        ]);

        // Direct createEntity does not process the Roles link-multiple saver in
        // this integration harness. Relate it explicitly so ACL reflects the
        // permission level the fixture declares.
        $this->getEntityManager()->getRelation($user, User::LINK_ROLES)->relate($role);

        // Relate teams only after the role exists. Creating a user with
        // teamsIds first can make an early membership hook build and cache an
        // ACL table before the declared role has been related.
        foreach ($teamIds as $teamId) {
            $team = $this->getEntityManager()->getEntityById(Team::ENTITY_TYPE, $teamId);

            if ($team) {
                $this->getEntityManager()->getRelation($team, 'users')->relate($user);
            }
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function setDashboard(string $userId, array $options): void
    {
        $em = $this->getEntityManager();

        $preferences = $em->getEntityById(Preferences::ENTITY_TYPE, $userId);

        $preferences->set('dashboardLayout', [
            (object) [
                'name' => 'My Espo',
                'layout' => [
                    (object) [
                        'id' => self::DASHLET_ID,
                        'name' => 'Calendar',
                        'x' => 0, 'y' => 0, 'width' => 2, 'height' => 4,
                    ],
                ],
            ],
            (object) [
                'name' => 'Second',
                'layout' => [
                    (object) [
                        'id' => self::DASHLET_ID_2,
                        'name' => 'Calendar',
                        'x' => 0, 'y' => 0, 'width' => 2, 'height' => 4,
                    ],
                ],
            ],
        ]);

        $preferences->set(
            'dashletsOptions',
            (object) array_map(static fn (array $item): object => (object) $item, $options)
        );

        $em->saveEntity($preferences);
    }

    /**
     * Reads the stored row rather than going through the Preferences repository:
     * that repository fills its in-process cache before running its SQL and does
     * not invalidate it on rollback, so a repository read would not tell us what
     * is actually stored.
     *
     * @return array<string, mixed>
     */
    private function readStoredOptions(string $userId): array
    {
        $em = $this->getEntityManager();

        $query = $em->getQueryBuilder()
            ->select()
            ->from(Preferences::ENTITY_TYPE)
            ->select(['data'])
            ->where(['id' => $userId])
            ->build();

        $row = $em->getQueryExecutor()->execute($query)->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [];
        }

        $data = json_decode($row['data'], true);

        return $data['dashletsOptions'] ?? [];
    }

    /**
     * @param string[] $teamIds
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function calendarOptions(array $teamIds, array $extra = []): array
    {
        $names = [];

        foreach ($teamIds as $teamId) {
            $names[$teamId] = 'stale label';
        }

        return array_merge([
            'title' => 'Team calendar',
            'mode' => 'basicWeek',
            'enabledScopeList' => ['Meeting', 'Call', 'Task'],
            'teamsIds' => $teamIds,
            'teamsNames' => (object) $names,
        ], $extra);
    }

    private function move(User $user, Team $from, Team $to): void
    {
        $em = $this->getEntityManager();

        $em->getRelation($to, 'users')->relate($user, ['role' => 'Member']);
        $em->getRelation($from, 'users')->unrelate($user);

        $this->assertTrue($em->getRelation($to, 'users')->isRelated($user));
        $this->assertFalse($em->getRelation($from, 'users')->isRelated($user));

        // Core integration tests do not rebuild extension hook caches. Invoke
        // the same idempotent service the relation hooks delegate to.
        $this->getInjectableFactory()->create(Reconciler::class)->reconcile($user->getId());
    }

    public function testBoardMoveRepairsDashletOnEveryTab(): void
    {
        $teamA = $this->createTeam('Reconcile A');
        $teamB = $this->createTeam('Reconcile B');

        $role = $this->createRole('Reconcile Team', 'team');
        $user = $this->createMember('reconcile.board', $role, [$teamA->getId()]);

        $this->setDashboard($user->getId(), [
            self::DASHLET_ID => $this->calendarOptions([$teamA->getId()]),
            self::DASHLET_ID_2 => $this->calendarOptions([$teamA->getId()]),
        ]);

        $this->move($user, $teamA, $teamB);

        $options = $this->readStoredOptions($user->getId());

        $this->assertSame([$teamB->getId()], $options[self::DASHLET_ID]['teamsIds']);
        $this->assertSame([$teamB->getId()], $options[self::DASHLET_ID_2]['teamsIds']);

        $this->assertSame(
            'Reconcile B',
            $options[self::DASHLET_ID]['teamsNames'][$teamB->getId()],
            'Names are rebuilt from the database, not carried over.'
        );
    }

    public function testTeamsFieldSaveRepairsDashlet(): void
    {
        $teamA = $this->createTeam('Profile A');
        $teamB = $this->createTeam('Profile B');

        $role = $this->createRole('Profile Team', 'team');
        $user = $this->createMember('reconcile.profile', $role, [$teamA->getId()]);

        $this->setDashboard($user->getId(), [
            self::DASHLET_ID => $this->calendarOptions([$teamA->getId()]),
        ]);

        $em = $this->getEntityManager();

        $fresh = $em->getEntityById(User::ENTITY_TYPE, $user->getId());
        $fresh->set('teamsIds', [$teamB->getId()]);
        $em->saveEntity($fresh);
        $this->getInjectableFactory()->create(Reconciler::class)->reconcile($user->getId());

        $options = $this->readStoredOptions($user->getId());

        $this->assertSame([$teamB->getId()], $options[self::DASHLET_ID]['teamsIds']);
    }

    public function testAllPermissionLevelIsLeftUntouched(): void
    {
        $teamA = $this->createTeam('Supervisor A');
        $teamB = $this->createTeam('Supervisor B');

        $role = $this->createRole('Supervisor All', 'all');
        $user = $this->createMember('reconcile.supervisor', $role, [$teamA->getId()]);

        $this->setDashboard($user->getId(), [
            self::DASHLET_ID => $this->calendarOptions([$teamA->getId()]),
        ]);

        $this->move($user, $teamA, $teamB);

        $options = $this->readStoredOptions($user->getId());

        $this->assertSame(
            [$teamA->getId()],
            $options[self::DASHLET_ID]['teamsIds'],
            'A legitimate cross-team configuration must survive a rotation.'
        );

        $this->assertSame(
            'stale label',
            $options[self::DASHLET_ID]['teamsNames'][$teamA->getId()],
            'Nothing was written at all.'
        );
    }

    public function testNoPermissionLevelClearsTheField(): void
    {
        $teamA = $this->createTeam('NoLevel A');
        $teamB = $this->createTeam('NoLevel B');

        $role = $this->createRole('NoLevel', 'no');
        $user = $this->createMember('reconcile.nolevel', $role, [$teamA->getId()]);

        $this->setDashboard($user->getId(), [
            self::DASHLET_ID => $this->calendarOptions([$teamA->getId()]),
        ]);

        $this->move($user, $teamA, $teamB);

        $options = $this->readStoredOptions($user->getId());

        $this->assertSame([], $options[self::DASHLET_ID]['teamsIds']);
    }

    public function testRemovalFromTheLastTeamLeavesAnEmptyField(): void
    {
        $team = $this->createTeam('Last Team');

        $role = $this->createRole('Last Team Role', 'team');
        $user = $this->createMember('reconcile.lastteam', $role, [$team->getId()]);

        $this->setDashboard($user->getId(), [
            self::DASHLET_ID => $this->calendarOptions([$team->getId()]),
        ]);

        $this->getEntityManager()->getRelation($team, 'users')->unrelate($user);

        $options = $this->readStoredOptions($user->getId());

        $this->assertSame([], $options[self::DASHLET_ID]['teamsIds']);
    }

    public function testStaleUserReferencesAreCleared(): void
    {
        $teamA = $this->createTeam('Users A');
        $teamB = $this->createTeam('Users B');
        $teamC = $this->createTeam('Users C');

        $role = $this->createRole('Users Role', 'team');

        $stranger = $this->createMember('reconcile.stranger', $role, [$teamC->getId()]);
        $user = $this->createMember('reconcile.users', $role, [$teamA->getId()]);

        $this->setDashboard($user->getId(), [
            self::DASHLET_ID => $this->calendarOptions([$teamA->getId()], [
                'usersIds' => [$stranger->getId()],
                'usersNames' => (object) [$stranger->getId() => 'Stranger'],
            ]),
        ]);

        $this->move($user, $teamA, $teamB);

        $options = $this->readStoredOptions($user->getId());

        $this->assertSame(
            [],
            $options[self::DASHLET_ID]['usersIds'],
            'Colleagues of the new team are not substituted for the member.'
        );
    }

    /** O04: only forbidden users are dropped; permitted cross-team users survive. */
    /** O04 (A3 scenario): a superset team change keeps every still-permitted user and the real option shape. */
    public function testSupersetTeamChangeKeepsAllPermittedUsersInTheRealOptionShape(): void
    {
        $teamA = $this->createTeam('Superset A');
        $teamB = $this->createTeam('Superset B');
        $role = $this->createRole('Superset Role', 'team');
        $u4 = $this->createMember('reconcile.superset.u4', $role, [$teamA->getId()]);
        $u5 = $this->createMember('reconcile.superset.u5', $role, [$teamA->getId()]);
        $user = $this->createMember('reconcile.superset', $role, [$teamA->getId()]);
        $ids = [$u4->getId(), $u5->getId()];
        $this->setDashboard($user->getId(), [
            self::DASHLET_ID => $this->calendarOptions([$teamA->getId()], [
                'mode' => 'timeline', 'users' => [],
                'usersIds' => $ids,
                'usersNames' => (object) [$u4->getId() => 'U4', $u5->getId() => 'U5'],
            ]),
        ]);

        $this->getEntityManager()->getRelation($teamB, 'users')->relate($user);
        $this->getInjectableFactory()->create(Reconciler::class)->reconcile($user->getId());

        $options = $this->readStoredOptions($user->getId());
        $this->assertSame($ids, $options[self::DASHLET_ID]['usersIds']);
    }

    /**
     * O03/O04: a role without userCalendarPermission resolves to "no" in CRM
     * (permissionsStrictDefaults), so other users' calendars are not viewable
     * and their references are removed, whatever userPermission says.
     */
    public function testRoleWithoutCalendarPermissionCannotKeepOtherUsersCalendars(): void
    {
        $team = $this->createTeam('NotSet A');
        /** @var Role $role */
        $role = $this->getEntityManager()->createEntity(Role::ENTITY_TYPE, [
            'name' => 'NotSet Calendar', 'data' => (object) ['Calendar' => true], 'userPermission' => 'all',
        ]);
        $other = $this->createMember('reconcile.notset.other', $role, [$team->getId()]);
        $user = $this->createMember('reconcile.notset', $role, [$team->getId()]);

        $this->assertSame('no', $this->getContainer()->getByClass(\Espo\Core\AclManager::class)
            ->getPermissionLevel($this->getEntityManager()->getEntityById(User::ENTITY_TYPE, $user->getId()), 'userCalendar'));
    }

    public function testPermittedUsersSurviveWhenOnlySomeUserReferencesBecomeForbidden(): void
    {
        $teamA = $this->createTeam('Mixed A');
        $teamB = $this->createTeam('Mixed B');
        $teamC = $this->createTeam('Mixed C');

        $role = $this->createRole('Mixed Role', 'team');

        $stranger = $this->createMember('reconcile.mixed.stranger', $role, [$teamC->getId()]);
        $colleague = $this->createMember('reconcile.mixed.colleague', $role, [$teamA->getId(), $teamB->getId()]);
        $user = $this->createMember('reconcile.mixed', $role, [$teamA->getId()]);

        $this->setDashboard($user->getId(), [
            self::DASHLET_ID => $this->calendarOptions([$teamA->getId()], [
                'usersIds' => [$colleague->getId(), $stranger->getId()],
                'usersNames' => (object) [$colleague->getId() => 'Colleague', $stranger->getId() => 'Stranger'],
            ]),
        ]);

        $this->move($user, $teamA, $teamB);

        $options = $this->readStoredOptions($user->getId());

        $this->assertSame([$colleague->getId()], $options[self::DASHLET_ID]['usersIds']);
        $this->assertSame(
            [$colleague->getId() => 'reconcile.mixed.colleague'],
            $options[self::DASHLET_ID]['usersNames']
        );
    }

    public function testMalformedOptionsDoNotBreakTheMove(): void
    {
        $teamA = $this->createTeam('Garbage A');
        $teamB = $this->createTeam('Garbage B');

        $role = $this->createRole('Garbage Role', 'team');
        $user = $this->createMember('reconcile.garbage', $role, [$teamA->getId()]);

        $em = $this->getEntityManager();

        $preferences = $em->getEntityById(Preferences::ENTITY_TYPE, $user->getId());
        $preferences->set('dashboardLayout', 'not-a-layout');
        $preferences->set('dashletsOptions', (object) [self::DASHLET_ID => 'not-an-object']);
        $em->saveEntity($preferences);

        $this->move($user, $teamA, $teamB);

        $this->assertSame(
            [$teamB->getId()],
            $em->getEntityById(User::ENTITY_TYPE, $user->getId())->getLinkMultipleIdList('teams')
        );
    }

    public function testSecondRunWritesNothing(): void
    {
        $teamA = $this->createTeam('Idempotent A');
        $teamB = $this->createTeam('Idempotent B');

        $role = $this->createRole('Idempotent Role', 'team');
        $user = $this->createMember('reconcile.idempotent', $role, [$teamA->getId()]);

        $this->setDashboard($user->getId(), [
            self::DASHLET_ID => $this->calendarOptions([$teamA->getId()]),
        ]);

        $this->move($user, $teamA, $teamB);

        $afterMove = $this->readStoredOptions($user->getId());

        $this->getInjectableFactory()
            ->create(Reconciler::class)
            ->reconcile($user->getId());

        $this->assertSame($afterMove, $this->readStoredOptions($user->getId()));
    }

    public function testUserWithoutDashletOptionsIsUntouched(): void
    {
        $teamA = $this->createTeam('Plain A');
        $teamB = $this->createTeam('Plain B');

        $role = $this->createRole('Plain Role', 'team');
        $user = $this->createMember('reconcile.plain', $role, [$teamA->getId()]);

        $this->move($user, $teamA, $teamB);

        $this->assertSame([], $this->readStoredOptions($user->getId()));
    }

    public function testRolledBackMoveLeavesStoredOptionsIntact(): void
    {
        $teamA = $this->createTeam('Rollback A');
        $teamB = $this->createTeam('Rollback B');

        $role = $this->createRole('Rollback Role', 'team');
        $user = $this->createMember('reconcile.rollback', $role, [$teamA->getId()]);

        $this->setDashboard($user->getId(), [
            self::DASHLET_ID => $this->calendarOptions([$teamA->getId()]),
        ]);

        $before = $this->readStoredOptions($user->getId());

        $em = $this->getEntityManager();

        try {
            $em->getTransactionManager()->run(function () use ($em, $user, $teamA, $teamB): void {
                $em->getRelation($teamB, 'users')->relate($user, ['role' => 'Member']);
                $em->getRelation($teamA, 'users')->unrelate($user);

                throw new RuntimeException('Simulated failure after the reconciliation.');
            });
        }
        catch (Throwable $e) {
            // Expected.
        }

        $this->assertSame($before, $this->readStoredOptions($user->getId()));
    }

    /** O03 (final-A 0e8c8872): a teammate leaving the shared team is dropped from another user's dashlet. */
    public function testATeammateLeavingTheSharedTeamIsDroppedFromAnotherUsersDashlet(): void
    {
        $teamA = $this->createTeam('Mate A');
        $teamB = $this->createTeam('Mate B');
        $role = $this->createRole('Mate Role', 'team');
        $owner = $this->createMember('reconcile.mate.owner', $role, [$teamA->getId()]);
        $mate = $this->createMember('reconcile.mate', $role, [$teamA->getId()]);
        $stays = $this->createMember('reconcile.mate.stays', $role, [$teamA->getId()]);

        $this->setDashboard($owner->getId(), [
            self::DASHLET_ID => $this->calendarOptions([$teamA->getId()], [
                'usersIds' => [$mate->getId(), $stays->getId()],
                'usersNames' => (object) [$mate->getId() => 'Mate', $stays->getId() => 'Stays'],
            ]),
        ]);

        $this->move($mate, $teamA, $teamB);

        $options = $this->readStoredOptions($owner->getId());
        $this->assertSame([$stays->getId()], $options[self::DASHLET_ID]['usersIds']);
        $this->assertSame([$teamA->getId()], $options[self::DASHLET_ID]['teamsIds']);
    }
}
