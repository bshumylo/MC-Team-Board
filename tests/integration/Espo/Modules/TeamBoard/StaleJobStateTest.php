<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Core\InjectableFactory;
use Espo\Modules\TeamBoard\Jobs\ApplyDueAssignments;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Board\Service as BoardService;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use tests\integration\Core\BaseTestCase;

/** Database coordination: stale dispatch snapshots and rollback after writes. */
class StaleJobStateTest extends BaseTestCase
{
    private function fixtures(): array
    {
        $em = $this->getEntityManager();
        $em->createEntity(User::ENTITY_TYPE, [
            'userName' => 'stale.job.admin', 'type' => User::TYPE_ADMIN, 'isActive' => true,
        ]);
        $user = $em->createEntity(User::ENTITY_TYPE, [
            'userName' => 'stale.job.person', 'type' => User::TYPE_REGULAR, 'isActive' => true,
        ]);
        $a = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'Stale A']);
        $b = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'Manual B']);
        foreach ([$a, $b] as $team) {
            $em->getRelation($team, 'users')->relate($user, ['role' => Position::MEMBER]);
        }
        $user->set('defaultTeamId', $a->getId());
        $em->saveEntity($user);
        return [$user, $a, $b];
    }

    public function testScanOfConfirmedPlanCannotOverrideLaterManualCrmChange(): void
    {
        [$user, $a, $b] = $this->fixtures();
        $em = $this->getEntityManager();
        $plan = $em->createEntity(AssignmentQuery::ENTITY_TYPE, [
            'memberId' => $user->getId(), 'teamId' => $a->getId(),
            'position' => Position::MEMBER, 'dateFrom' => date('Y-m-d'),
            'status' => Status::CONFIRMED,
        ]);
        // The dispatcher already holds this entity when the native save wins.
        $user->set('defaultTeamId', $b->getId());
        $em->saveEntity($user);
        $this->assertSame(Status::CONFIRMED, $plan->get('status'));
        $this->assertSame(Status::DRAFT,
            $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $plan->getId())->get('status'));

        $query = $this->getMockBuilder(AssignmentQuery::class)
            ->setConstructorArgs([$em])->onlyMethods(['findDue'])->getMock();
        $query->expects($this->once())->method('findDue')->willReturn([$plan]);
        $this->getInjectableFactory()->createWith(ApplyDueAssignments::class, ['query' => $query])->run();

        $freshUser = $em->getEntityById(User::ENTITY_TYPE, $user->getId());
        $freshPlan = $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $plan->getId());
        $this->assertSame($b->getId(), $freshUser->get('defaultTeamId'));
        $this->assertTrue($em->getRelation($b, 'users')->isRelated($freshUser));
        $this->assertSame(Status::DRAFT, $freshPlan->get('status'));
        $this->assertNull($freshPlan->get('appliedAt'));
    }

    public function testScanOfEndedPeriodCannotUndoLaterManualMembership(): void
    {
        [$user, $a, $b] = $this->fixtures();
        $em = $this->getEntityManager();
        $ended = $em->createEntity(AssignmentQuery::ENTITY_TYPE, [
            'memberId' => $user->getId(), 'teamId' => $a->getId(),
            'position' => Position::MEMBER,
            'dateFrom' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d'),
            'dateTo' => date('Y-m-d'), 'status' => Status::CONFIRMED,
            'appliedAt' => date('Y-m-d H:i:s'),
        ]);
        $user->set('defaultTeamId', $b->getId());
        $em->saveEntity($user);
        $this->assertNull($ended->get('endedAt'));
        $this->assertNotNull($em->getEntityById(AssignmentQuery::ENTITY_TYPE, $ended->getId())->get('endedAt'));

        $query = $this->getMockBuilder(AssignmentQuery::class)
            ->setConstructorArgs([$em])->onlyMethods(['findEndedApplied'])->getMock();
        $query->expects($this->once())->method('findEndedApplied')->willReturn([$ended]);
        $this->getInjectableFactory()->createWith(ApplyDueAssignments::class, ['query' => $query])->run();

        $this->assertTrue($em->getRelation($a, 'users')->isRelated($user));
        $this->assertTrue($em->getRelation($b, 'users')->isRelated($user));
        $this->assertSame($b->getId(), $em->getEntityById(User::ENTITY_TYPE, $user->getId())->get('defaultTeamId'));
    }

    public static function predecessorCases(): array
    {
        return ['standalone plan' => [false], 'closes actual predecessor' => [true]];
    }

    #[DataProvider('predecessorCases')]
    public function testFailureAfterNativeMoveRollsBackAndCanBeRetried(bool $closesPredecessor): void
    {
        [$user, $a, $b] = $this->fixtures();
        $em = $this->getEntityManager();
        $factory = $this->getInjectableFactory();
        $g = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'Rollback destination']);
        $previous = null;
        if ($closesPredecessor) {
            $previous = $em->getRDBRepository(AssignmentQuery::ENTITY_TYPE)->where([
                'memberId' => $user->getId(), 'teamId' => $a->getId(), 'appliedAt!=' => null,
            ])->findOne();
            $this->assertNotNull($previous);
            $yesterday = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
            $previous->set(['dateFrom' => $yesterday, 'actualDateFrom' => $yesterday]);
            $em->saveEntity($previous);
            $plan = $factory->create(AssignmentEditor::class)->create(
                $user->getId(), $g->getId(), Position::MEMBER, date('Y-m-d'),
                null, Status::CONFIRMED, null, Position::DEFAULT_LIST,
            );
            $this->assertSame($previous->getId(), $plan->get('supersedesId'));
            $this->assertSame(date('Y-m-d'),
                $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $previous->getId())->get('dateTo'));
        } else {
            $plan = $em->createEntity(AssignmentQuery::ENTITY_TYPE, [
                'memberId' => $user->getId(), 'teamId' => $g->getId(),
                'position' => Position::MEMBER, 'dateFrom' => date('Y-m-d'),
                'status' => Status::CONFIRMED,
            ]);
        }
        $admin = $em->getRDBRepositoryByClass(User::class)->where(['type' => User::TYPE_ADMIN])->findOne();
        $realService = $factory->createWith(BoardService::class, ['user' => $admin]);
        $failingService = $this->createMock(BoardService::class);
        $failingService->expects($this->once())->method('move')
            ->willReturnCallback(static function (...$args) use ($realService): object {
                $realService->move(...$args);
                throw new \RuntimeException('Simulated failure after native relationship/default writes.');
            });
        $jobFactory = $this->createMock(InjectableFactory::class);
        $jobFactory->expects($this->once())->method('createWith')
            ->with(BoardService::class, $this->anything())->willReturn($failingService);
        $factory->createWith(ApplyDueAssignments::class, ['injectableFactory' => $jobFactory])->run();

        $fresh = $em->getEntityById(User::ENTITY_TYPE, $user->getId());
        $this->assertSame($a->getId(), $fresh->get('defaultTeamId'));
        $this->assertTrue($em->getRelation($a, 'users')->isRelated($fresh));
        $this->assertTrue($em->getRelation($b, 'users')->isRelated($fresh));
        $this->assertFalse($em->getRelation($g, 'users')->isRelated($fresh));
        $this->assertNull($em->getEntityById(AssignmentQuery::ENTITY_TYPE, $plan->getId())->get('appliedAt'));
        $this->assertSame(Status::CONFIRMED,
            $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $plan->getId())->get('status'));
        if ($previous) {
            $this->assertNull($em->getEntityById(AssignmentQuery::ENTITY_TYPE, $previous->getId())->get('endedAt'));
        }

        $factory->create(ApplyDueAssignments::class)->run();
        $fresh = $em->getEntityById(User::ENTITY_TYPE, $user->getId());
        $this->assertSame($g->getId(), $fresh->get('defaultTeamId'));
        $this->assertFalse($em->getRelation($a, 'users')->isRelated($fresh));
        $this->assertTrue($em->getRelation($b, 'users')->isRelated($fresh));
        $this->assertTrue($em->getRelation($g, 'users')->isRelated($fresh));
        $this->assertNotNull($em->getEntityById(AssignmentQuery::ENTITY_TYPE, $plan->getId())->get('appliedAt'));
        if ($previous) {
            $this->assertNotNull($em->getEntityById(AssignmentQuery::ENTITY_TYPE, $previous->getId())->get('endedAt'));
        }
    }
}
