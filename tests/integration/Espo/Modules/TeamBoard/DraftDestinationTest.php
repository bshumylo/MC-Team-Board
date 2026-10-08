<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Core\Utils\DateTime;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Timeline\Service;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;
use tests\integration\Core\BaseTestCase;

/**
 * D8-C U06 (review №18, №21), T05: a Draft in effect on the viewed date shows
 * the person once — at the destination, pale, with an origin marker — and is
 * counted there. The CRM state stays unchanged.
 */
class DraftDestinationTest extends BaseTestCase
{
    private function em(): EntityManager
    {
        return $this->getContainer()->getByClass(EntityManager::class);
    }

    private function today(): string
    {
        return $this->getContainer()->getByClass(DateTime::class)->getToday()->toString();
    }

    private function tomorrow(): string
    {
        return (new \DateTimeImmutable($this->today()))->modify('+1 day')->format('Y-m-d');
    }

    private function service(): Service
    {
        return $this->getInjectableFactory()->create(Service::class);
    }

    private function squad(string $name): Entity
    {
        return $this->em()->createEntity('TeamBoardSquad', [
            'name' => $name,
            'positionList' => ['Lead', 'Dev'],
            'isArchived' => false,
        ]);
    }

    private function boardMember(string $name): Entity
    {
        return $this->em()->createEntity('TeamBoardMember', ['name' => $name, 'isArchived' => false]);
    }

    /** @return stdClass[] entries of this board member in a squad */
    private function inSquad(stdClass $timeline, string $squadId, string $boardMemberId): array
    {
        foreach ($timeline->teams as $team) {
            if ($team->boardSquadId === $squadId) {
                return array_values(array_filter(
                    $team->members,
                    fn (stdClass $m) => $m->boardMemberId === $boardMemberId
                ));
            }
        }
        $this->fail('Squad is not on the board: ' . $squadId);
    }

    private function inReserve(stdClass $timeline, string $boardMemberId): bool
    {
        foreach ($timeline->reserve as $item) {
            if ($item->boardMemberId === $boardMemberId) {
                return true;
            }
        }
        return false;
    }

    public function testBoardOnlyDraftShowsPersonOnlyAtDestinationWithOrigin(): void
    {
        $a = $this->squad('U06 Origin A');
        $b = $this->squad('U06 Dest B');
        $member = $this->boardMember('U06 Board Person');
        $service = $this->service();

        $service->createAssignment((object) [
            'boardMemberId' => $member->getId(), 'boardSquadId' => $a->getId(),
            'position' => 'Dev', 'dateFrom' => $this->today(), 'status' => Status::CONFIRMED,
        ]);
        $service->createAssignment((object) [
            'boardMemberId' => $member->getId(), 'boardSquadId' => $b->getId(),
            'position' => 'Dev', 'dateFrom' => $this->tomorrow(), 'status' => Status::DRAFT,
        ]);

        $timeline = $service->getTimeline($this->tomorrow(), $this->today(), $this->tomorrow());

        $this->assertSame([], $this->inSquad($timeline, $a->getId(), $member->getId()), 'not duplicated at origin');
        $dest = $this->inSquad($timeline, $b->getId(), $member->getId());
        $this->assertCount(1, $dest);
        $this->assertSame(Status::DRAFT, $dest[0]->status);
        $this->assertFalse($dest[0]->draftOrigin->isReserve);
        $this->assertSame('U06 Origin A', $dest[0]->draftOrigin->name);
        $this->assertFalse($this->inReserve($timeline, $member->getId()));

        // Today (before the Draft starts) the person is still only at A.
        $now = $service->getTimeline($this->today(), $this->today(), $this->tomorrow());
        $this->assertCount(1, $this->inSquad($now, $a->getId(), $member->getId()));
        $this->assertSame([], $this->inSquad($now, $b->getId(), $member->getId()));
        $this->assertNull($this->inSquad($now, $a->getId(), $member->getId())[0]->draftOrigin);
    }

    public function testCrmUserDraftFromReserveIsNotAlsoShownInReserveAndCrmIsUnchanged(): void
    {
        $em = $this->em();
        $team = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'U06 Korea']);
        /** @var User $user */
        $user = $em->createEntity(User::ENTITY_TYPE, [
            'userName' => 'u06.reserve', 'lastName' => 'U06 Reserve',
            'type' => User::TYPE_REGULAR, 'isActive' => true,
        ]);
        $service = $this->service();
        $baseline = $service->getTimeline($this->tomorrow(), $this->today(), $this->tomorrow());
        $memberId = null;
        foreach ($baseline->reserve as $item) {
            if ($item->userId === $user->getId()) {
                $memberId = $item->boardMemberId;
            }
        }
        $this->assertNotNull($memberId, 'starts in reserve');
        $squadId = null;
        foreach ($baseline->teams as $item) {
            if ($item->teamId === $team->getId()) {
                $squadId = $item->boardSquadId;
            }
        }
        $this->assertNotNull($squadId);

        $service->createAssignment((object) [
            'memberId' => $user->getId(), 'teamId' => $team->getId(),
            'position' => Position::MEMBER, 'dateFrom' => $this->tomorrow(), 'status' => Status::DRAFT,
        ]);
        $timeline = $service->getTimeline($this->tomorrow(), $this->today(), $this->tomorrow());

        $dest = $this->inSquad($timeline, $squadId, $memberId);
        $this->assertCount(1, $dest);
        $this->assertTrue($dest[0]->draftOrigin->isReserve);
        $this->assertFalse($this->inReserve($timeline, $memberId), 'not duplicated in reserve');

        // T05: the Draft does not touch CRM.
        $reloaded = $em->getRDBRepositoryByClass(User::class)->getById($user->getId());
        $this->assertNull($reloaded->get('defaultTeamId'));
        $this->assertSame(0, $em->getRDBRepositoryByClass(User::class)
            ->getRelation($reloaded, 'teams')->count());
    }

    public function testDistantDraftIsPendingWithoutDisplacingCurrentOrEarlierDraftDestination(): void
    {
        $a = $this->squad('U02 Current A');
        $b = $this->squad('U02 Tomorrow B');
        $c = $this->squad('U02 Distant C');
        $member = $this->boardMember('U02 Distant Person');
        $service = $this->service();
        foreach ([[$a, $this->today(), Status::CONFIRMED], [$b, $this->tomorrow(), Status::DRAFT],
            [$c, (new \DateTimeImmutable($this->today()))->modify('+15 years')->format('Y-m-d'), Status::DRAFT]]
            as [$squad, $from, $status]) {
            $service->createAssignment((object) [
                'boardMemberId' => $member->getId(), 'boardSquadId' => $squad->getId(),
                'position' => 'Dev', 'dateFrom' => $from, 'status' => $status,
            ]);
        }
        $now = $service->getTimeline($this->today(), $this->today(), $this->tomorrow());
        $this->assertCount(1, $this->inSquad($now, $a->getId(), $member->getId()));
        $this->assertSame([], $this->inSquad($now, $b->getId(), $member->getId()));
        $this->assertSame([], $this->inSquad($now, $c->getId(), $member->getId()));
        $plans = array_values(array_filter($now->pendingDrafts,
            fn (stdClass $plan) => $plan->id === $member->getId()));
        $this->assertCount(2, $plans, 'no arbitrary horizon loses the distant Draft');
        $tomorrow = $service->getTimeline($this->tomorrow(), $this->today(), $this->tomorrow());
        $this->assertCount(1, $this->inSquad($tomorrow, $b->getId(), $member->getId()));
        $this->assertSame([], $this->inSquad($tomorrow, $c->getId(), $member->getId()));
    }

    public function testCrmUserDraftIntoBoardOnlyTeamIsShownAtDestinationOnly(): void
    {
        $em = $this->em();
        $origin = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'U06 CRM Origin']);
        $dest = $this->squad('U06 Board Dest');
        /** @var User $user */
        $user = $em->createEntity(User::ENTITY_TYPE, [
            'userName' => 'u06.crm', 'lastName' => 'U06 Crm',
            'type' => User::TYPE_REGULAR, 'isActive' => true,
        ]);
        $em->getRelation($origin, 'users')->relate($user, ['role' => Position::MEMBER]);
        $user = $em->getRDBRepositoryByClass(User::class)->getById($user->getId());
        $user->set(['defaultTeamId' => $origin->getId()]);
        $em->saveEntity($user);

        $service = $this->service();
        $service->createAssignment((object) [
            'memberId' => $user->getId(), 'boardSquadId' => $dest->getId(),
            'position' => 'Dev', 'dateFrom' => $this->tomorrow(), 'status' => Status::DRAFT,
        ]);
        $timeline = $service->getTimeline($this->tomorrow(), $this->today(), $this->tomorrow());

        $originSquadId = null;
        foreach ($timeline->teams as $item) {
            if ($item->teamId === $origin->getId()) {
                $originSquadId = $item->boardSquadId;
            }
        }
        $this->assertNotNull($originSquadId);
        $boardMemberId = null;
        foreach ($timeline->teams as $item) {
            foreach ($item->members as $m) {
                if ($m->userId === $user->getId()) {
                    $boardMemberId = $m->boardMemberId;
                }
            }
        }
        $this->assertNotNull($boardMemberId, 'draft destination is visible');
        $this->assertSame([], $this->inSquad($timeline, $originSquadId, $boardMemberId));
        $shown = $this->inSquad($timeline, $dest->getId(), $boardMemberId);
        $this->assertCount(1, $shown);
        $this->assertSame('U06 CRM Origin', $shown[0]->draftOrigin->name);

        $reloaded = $em->getRDBRepositoryByClass(User::class)->getById($user->getId());
        $this->assertSame($origin->getId(), $reloaded->get('defaultTeamId'), 'T05: CRM default unchanged');
    }

    public function testOverlappingDraftsShowThePersonOnceAtTheLatestDraft(): void
    {
        $a = $this->squad('U06 Overlap A');
        $b = $this->squad('U06 Overlap B');
        $c = $this->squad('U06 Overlap C');
        $member = $this->boardMember('U06 Overlap Person');
        $service = $this->service();
        $dayAfter = (new \DateTimeImmutable($this->today()))->modify('+2 day')->format('Y-m-d');
        $third = (new \DateTimeImmutable($this->today()))->modify('+3 day')->format('Y-m-d');

        $service->createAssignment((object) [
            'boardMemberId' => $member->getId(), 'boardSquadId' => $a->getId(),
            'position' => 'Dev', 'dateFrom' => $this->today(), 'status' => Status::CONFIRMED,
        ]);
        foreach ([[$b, $this->tomorrow()], [$c, $dayAfter], [$b, $third]] as [$squad, $from]) {
            $service->createAssignment((object) [
                'boardMemberId' => $member->getId(), 'boardSquadId' => $squad->getId(),
                'position' => 'Dev', 'dateFrom' => $from, 'status' => Status::DRAFT,
            ]);
        }

        $timeline = $service->getTimeline($third, $this->today(), $third);
        $shownB = $this->inSquad($timeline, $b->getId(), $member->getId());
        $shownC = $this->inSquad($timeline, $c->getId(), $member->getId());

        $this->assertCount(1, $shownB, 'shown once, at the Draft that started last');
        $this->assertSame($third, $shownB[0]->dateFrom);
        $this->assertSame([], $shownC, 'an older overlapping Draft is not shown as a second copy');
        $this->assertSame([], $this->inSquad($timeline, $a->getId(), $member->getId()));
        $this->assertSame('U06 Overlap A', $shownB[0]->draftOrigin->name);
    }
}
