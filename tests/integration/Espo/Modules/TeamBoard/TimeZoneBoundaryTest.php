<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Jobs\ApplyDueAssignments;
use Espo\Modules\TeamBoard\Jobs\CleanupMissedDrafts;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use tests\integration\Core\BaseTestCase;

/**
 * D1 — the board's day boundary (missed-draft cleanup, due-assignment
 * application) must follow the CRM's configured `timeZone`, never UTC or the
 * host clock. Production code already reads it through the core `dateTime`
 * service (`Espo\Core\Utils\DateTime::getToday()`), which the core
 * `Espo\Core\Loaders\DateTime` loader builds from `Config::get('timeZone')`
 * (see `application/Espo/Core/Loaders/DateTime.php`).
 *
 * Deterministic without clock mocking: the CRM `timeZone` is set (via
 * `initData['config']`, applied before the app boots — see
 * `Espo\Core\Utils\Config` write in `tests/integration/Core/DataLoader.php::loadConfig()`)
 * to a zone whose local calendar date differs from the UTC calendar date
 * *right now* — `Pacific/Kiritimati` (UTC+14) when the current UTC hour is
 * >= 10, else `Pacific/Pago_Pago` (UTC-11). One branch always applies, so the
 * CRM-local "today" always differs from the UTC "today" for the whole run.
 *
 * Each job is given two fixtures dated relative to the CRM-local "today":
 * one at CRM-local today, one at CRM-local yesterday (Cleanup) / tomorrow
 * (Apply). Whichever branch is picked, exactly one of the two fixtures lands
 * on a date a UTC-clock "today" would score the opposite way. Both
 * fixtures are still asserted every run, so the test also documents the
 * plain (non-divergent) half of each requirement.
 */
class TimeZoneBoundaryTest extends BaseTestCase
{
    private const ASSIGNMENT = 'TeamBoardAssignment';

    private string $zone;

    protected function setUp(): void
    {
        $this->zone = self::pickZone();
        $this->initData = ['config' => ['timeZone' => $this->zone]];
        parent::setUp();
    }

    private static function pickZone(): string
    {
        return ((int) gmdate('H') >= 10) ? 'Pacific/Kiritimati' : 'Pacific/Pago_Pago';
    }

    private function crmToday(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone($this->zone)))->format('Y-m-d');
    }

    private function utcToday(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    }

    private function offset(string $day, string $modify): string
    {
        return (new DateTimeImmutable($day))->modify($modify)->format('Y-m-d');
    }

    protected function getEntityManager(): EntityManager
    {
        return $this->getContainer()->getByClass(EntityManager::class);
    }

    private function createTeam(string $name): Team
    {
        /** @var Team */
        return $this->getEntityManager()->createEntity(Team::ENTITY_TYPE, ['name' => $name]);
    }

    private function createMember(string $userName): User
    {
        /** @var User */
        return $this->getEntityManager()->createEntity(User::ENTITY_TYPE, [
            'userName' => $userName,
            'lastName' => $userName,
            'type' => User::TYPE_REGULAR,
            'isActive' => true,
        ]);
    }

    private function createDraft(User $member, Team $team, string $dateFrom): Entity
    {
        return $this->getEntityManager()->createEntity(self::ASSIGNMENT, [
            'memberId' => $member->getId(),
            'teamId' => $team->getId(),
            'position' => Position::MEMBER,
            'dateFrom' => $dateFrom,
            'dateTo' => null,
            'status' => Status::DRAFT,
        ]);
    }

    private function createConfirmed(User $member, Team $team, string $dateFrom): Entity
    {
        return $this->getEntityManager()->createEntity(self::ASSIGNMENT, [
            'memberId' => $member->getId(),
            'teamId' => $team->getId(),
            'position' => Position::MEMBER,
            'dateFrom' => $dateFrom,
            'dateTo' => null,
            'status' => Status::CONFIRMED,
        ]);
    }

    private function exists(Entity $entity): bool
    {
        return $this->getEntityManager()->getEntityById(self::ASSIGNMENT, $entity->getId()) !== null;
    }

    private function isMember(Team $team, User $user): bool
    {
        return $this->getEntityManager()->getRelation($team, 'users')->isRelated($user);
    }

    private function ensureAdmin(): void
    {
        $repository = $this->getEntityManager()->getRDBRepositoryByClass(User::class);
        $admin = $repository->where([
            'type' => User::TYPE_ADMIN,
            'isActive' => true,
        ])->findOne();

        if (!$admin) {
            $this->getEntityManager()->createEntity(User::ENTITY_TYPE, [
                'userName' => 'teamboard.timezone.test.admin',
                'lastName' => 'TeamBoard Test Admin',
                'type' => User::TYPE_ADMIN,
                'isActive' => true,
            ]);
        }
    }

    private function runCleanupJob(): void
    {
        $this->getInjectableFactory()->create(CleanupMissedDrafts::class)->run();
    }

    private function runApplyJob(): void
    {
        $this->ensureAdmin();
        $this->getInjectableFactory()->create(ApplyDueAssignments::class)->run();
    }

    /**
     * Guards the rest of the test: confirms the CRM config actually carries
     * the zone this run picked, and that the zone's local calendar date
     * really differs from the UTC calendar date right now. If either check
     * failed, the assertions below would not distinguish anything.
     */
    public function testTheChosenZoneIsAppliedAndItsLocalDateDiffersFromUtc(): void
    {
        $this->assertSame($this->zone, $this->getConfig()->get('timeZone'));
        $this->assertNotSame($this->utcToday(), $this->crmToday());
    }

    public function testCleanupMissedDraftsFollowsTheCrmLocalDayNotUtc(): void
    {
        $team = $this->createTeam('TZ Cleanup');
        $member = $this->createMember('tz.cleanup.one');

        // CRM-local today: must survive the whole of its own day.
        $today = $this->createDraft($member, $team, $this->crmToday());
        // CRM-local yesterday: must be removed, even where (branch-dependent)
        // that date string equals the UTC "today".
        $yesterday = $this->createDraft($member, $team, $this->offset($this->crmToday(), '-1 day'));

        $this->runCleanupJob();

        $this->assertTrue($this->exists($today), 'A draft dated CRM-local today must survive its own day.');
        $this->assertFalse($this->exists($yesterday), 'A draft dated CRM-local yesterday must be removed.');
    }

    public function testApplyDueAssignmentsFollowsTheCrmLocalDayNotUtc(): void
    {
        // Two different destination teams, so applying (or not applying) one
        // fixture can never interact with the other through the shared-team
        // move/hook machinery — each assertion isolates a single date.
        $dueTeam = $this->createTeam('TZ Apply Due');
        $notYetDueTeam = $this->createTeam('TZ Apply Not Yet Due');
        $member = $this->createMember('tz.apply.one');

        // CRM-local today: due now, must be applied.
        $due = $this->createConfirmed($member, $dueTeam, $this->crmToday());
        // CRM-local tomorrow: not due yet, even where (branch-dependent) that
        // date string equals the UTC "today".
        $notYetDue = $this->createConfirmed($member, $notYetDueTeam, $this->offset($this->crmToday(), '+1 day'));

        $this->runApplyJob();

        $this->assertTrue($this->isMember($dueTeam, $member));
        $reloadedDue = $this->getEntityManager()->getEntityById(self::ASSIGNMENT, $due->getId());
        $this->assertNotNull($reloadedDue?->get('appliedAt'), 'A plan starting CRM-local today must be applied.');

        $this->assertFalse($this->isMember($notYetDueTeam, $member));
        $reloadedNotYetDue = $this->getEntityManager()->getEntityById(self::ASSIGNMENT, $notYetDue->getId());
        $this->assertNull(
            $reloadedNotYetDue?->get('appliedAt'),
            'A plan starting CRM-local tomorrow must not be applied yet.'
        );
    }
}
