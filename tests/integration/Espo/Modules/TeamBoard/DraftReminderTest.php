<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Entities\Notification;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Board\Position;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentQuery;
use Espo\Modules\TeamBoard\Tools\Timeline\DraftReminder;
use Espo\Modules\TeamBoard\Tools\Timeline\Service;
use Espo\Modules\TeamBoard\Tools\Timeline\Status;
use tests\integration\Core\BaseTestCase;

class DraftReminderTest extends BaseTestCase
{
    private function day(int $offset): string
    {
        return (new \DateTimeImmutable())->modify("+$offset days")->format('Y-m-d');
    }

    private function editorRole(bool $edit): array
    {
        $level = $edit ? 'all' : 'no';
        return ['data' => [
            'TeamBoard' => true,
            'TeamBoardAssignment' => [
                'read' => 'all', 'edit' => $level, 'create' => $level, 'delete' => $level,
            ],
            'User' => ['read' => 'all', 'edit' => 'no'],
            'Team' => ['read' => 'all', 'edit' => 'no'],
        ]];
    }

    private function fixture(): array
    {
        $em = $this->getEntityManager();
        $person = $em->createEntity(User::ENTITY_TYPE, [
            'userName' => 'reminder.person', 'type' => User::TYPE_REGULAR, 'isActive' => true,
        ]);
        $team = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'Reminder team']);
        $registry = $this->getInjectableFactory()->create(Registry::class);
        return [$person, $team, $registry->memberForUser($person->getId()), $registry->squadForTeam($team->getId())];
    }

    private function countNotifications(User $user, string $assignmentId): int
    {
        return $this->getEntityManager()->getRDBRepositoryByClass(Notification::class)->where([
            'userId' => $user->getId(),
            'relatedType' => AssignmentQuery::ENTITY_TYPE,
            'relatedId' => $assignmentId,
        ])->count();
    }

    public function testJobNotifiesEveryAuthorizedEditorOnceAndExcludesViewerAndPortal(): void
    {
        [$person, $team, $member, $squad] = $this->fixture();
        $editor = $this->createUser('reminder.editor', $this->editorRole(true));
        $viewer = $this->createUser('reminder.viewer', $this->editorRole(false));
        $portal = $this->createUser('reminder.portal', $this->editorRole(true), true);
        $plan = $this->getInjectableFactory()->create(AssignmentEditor::class)->createShadow(
            $member->getId(), $squad->getId(), $person->getId(), $team->getId(),
            Position::MEMBER, $this->day(7), null, Status::DRAFT, null, Position::DEFAULT_LIST,
        );
        $reminder = $this->getInjectableFactory()->create(DraftReminder::class);
        $reminder->notifyUpcoming(date('Y-m-d'));
        $reminder->notifyUpcoming(date('Y-m-d'));

        $this->assertSame(1, $this->countNotifications($editor, $plan->getId()));
        $this->assertSame(0, $this->countNotifications($viewer, $plan->getId()));
        $this->assertSame(0, $this->countNotifications($portal, $plan->getId()));
        $ids = $this->getEntityManager()->getEntityById(AssignmentQuery::ENTITY_TYPE, $plan->getId())
            ->get('reminderUserIds');
        $this->assertContains($editor->getId(), $ids);
        $notification = $this->getEntityManager()->getRDBRepositoryByClass(Notification::class)->where([
            'userId' => $editor->getId(), 'relatedId' => $plan->getId(),
        ])->findOne();
        $this->assertNotNull($notification);
        $this->assertSame(DraftReminder::NOTIFICATION_TYPE, $notification->get('type'));
        $this->assertSame('TeamBoard', $notification->get('data')->entityType);
        $this->assertSame($member->getId(), $notification->get('data')->entityId);
        $this->assertStringContainsString('{entity}', (string) $notification->get('message'));
        $this->assertStringContainsString('TeamBoard:', (string) $notification->get('message'));
    }

    public function testBoardOnlyPersonReminderUsesRecipientLanguageAndCardLink(): void
    {
        $em = $this->getEntityManager();
        $editor = $this->createUser('reminder.uk.editor', $this->editorRole(true));
        $preferences = $em->getEntityById('Preferences', $editor->getId());
        $this->assertNotNull($preferences);
        $preferences->set('language', 'uk_UA');
        $em->saveEntity($preferences);
        $member = $em->createEntity('TeamBoardMember', ['firstName' => 'Ім’я', 'lastName' => '[Test]']);
        $squad = $em->createEntity('TeamBoardSquad', ['name' => 'Reminder board team']);
        $plan = $this->getInjectableFactory()->create(AssignmentEditor::class)->createShadow(
            $member->getId(), $squad->getId(), null, null, Position::MEMBER,
            $this->day(7), null, Status::DRAFT, null, Position::DEFAULT_LIST,
        );
        $this->getInjectableFactory()->create(DraftReminder::class)->notifyUpcoming(date('Y-m-d'));
        $notification = $em->getRDBRepositoryByClass(Notification::class)->where([
            'userId' => $editor->getId(), 'relatedId' => $plan->getId(),
        ])->findOne();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('TeamBoard: Для {entity}', (string) $notification->get('message'));
        $this->assertStringContainsString('незатверджене переміщення', (string) $notification->get('message'));
        $this->assertSame($member->getId(), $notification->get('data')->entityId);
    }

    public function testDraftCreatedInsideWindowNotifiesImmediatelyAndReturnToDraftNotifiesAgain(): void
    {
        [$person, $team] = $this->fixture();
        $editor = $this->createUser('reminder.immediate.editor', $this->editorRole(true));
        $this->authenticate($editor->getUserName());
        $service = $this->getInjectableFactory()->create(Service::class);
        $payload = $service->createAssignment((object) [
            'memberId' => $person->getId(), 'teamId' => $team->getId(),
            'position' => Position::MEMBER, 'dateFrom' => $this->day(3),
            'dateTo' => null, 'status' => Status::DRAFT,
            // SEC-MASS-01: protected/history fields and the obsolete
            // assignment note must not be accepted from the request body.
            'note' => '<script>alert(1)</script>',
            'appliedAt' => '2020-01-01 00:00:00',
            'actualDateFrom' => '2020-01-01',
            'actualDateTo' => '2020-01-02',
            'reminderUserIds' => ['spoofed-user'],
        ]);
        $plan = $this->getEntityManager()->getRDBRepository(AssignmentQuery::ENTITY_TYPE)->where([
            'memberId' => $person->getId(), 'teamId' => $team->getId(), 'status' => Status::DRAFT,
        ])->findOne();
        $this->assertNotNull($plan);
        $this->assertSame(1, $this->countNotifications($editor, $plan->getId()));
        $this->assertNull($plan->get('note'));
        $this->assertNull($plan->get('appliedAt'));
        $this->assertNull($plan->get('actualDateFrom'));
        $this->assertNull($plan->get('actualDateTo'));
        $this->assertNotContains('spoofed-user', $plan->get('reminderUserIds'));
        $card = null;
        foreach ($payload->teams as $entry) {
            if ($entry->teamId === $team->getId()) {
                $card = $entry->members[0] ?? null;
            }
        }
        $this->assertNotNull($card);
        $this->assertContains('draftDue', $card->warnings);

        $service->updateAssignment($plan->getId(), (object) ['status' => Status::CONFIRMED]);
        $service->updateAssignment($plan->getId(), (object) ['status' => Status::DRAFT]);
        $this->assertSame(2, $this->countNotifications($editor, $plan->getId()));
    }

    /** O02: a confirmed plan returned to Draft by a manual CRM change notifies immediately, once. */
    public function testManualCrmChangeReturningAPlanToDraftNotifiesImmediatelyOnce(): void
    {
        [$person, $team, $member, $squad] = $this->fixture();
        $editor = $this->createUser('reminder.manual.editor', $this->editorRole(true));
        $plan = $this->getInjectableFactory()->create(AssignmentEditor::class)->createShadow(
            $member->getId(), $squad->getId(), $person->getId(), $team->getId(),
            Position::MEMBER, $this->day(3), null, Status::CONFIRMED, null, Position::DEFAULT_LIST,
        );
        $this->assertSame(0, $this->countNotifications($editor, $plan->getId()));

        $em = $this->getEntityManager();
        $manualTeam = $em->createEntity(Team::ENTITY_TYPE, ['name' => 'Reminder manual team']);
        $em->getRelation($manualTeam, 'users')->relate($person);
        $person = $em->getEntityById(User::ENTITY_TYPE, $person->getId());
        $person->set('defaultTeamId', $manualTeam->getId());
        $em->saveEntity($person);

        $this->assertSame(Status::DRAFT, $em->getEntityById(AssignmentQuery::ENTITY_TYPE, $plan->getId())?->get('status'));
        $this->assertSame(1, $this->countNotifications($editor, $plan->getId()));

        $this->getInjectableFactory()->create(DraftReminder::class)->notifyUpcoming(date('Y-m-d'));
        $this->assertSame(1, $this->countNotifications($editor, $plan->getId()));
    }

    public function testDraftOutsideWindowDoesNotNotify(): void
    {
        [$person, $team, $member, $squad] = $this->fixture();
        $editor = $this->createUser('reminder.later.editor', $this->editorRole(true));
        $plan = $this->getInjectableFactory()->create(AssignmentEditor::class)->createShadow(
            $member->getId(), $squad->getId(), $person->getId(), $team->getId(),
            Position::MEMBER, $this->day(8), null, Status::DRAFT, null, Position::DEFAULT_LIST,
        );
        $this->getInjectableFactory()->create(DraftReminder::class)->notifyUpcoming(date('Y-m-d'));
        $this->assertSame(0, $this->countNotifications($editor, $plan->getId()));
    }

    public function testFutureDraftMarksCurrentCardOnlyInsideReminderWindow(): void
    {
        $em = $this->getEntityManager();
        $currentSquad = $em->createEntity('TeamBoardSquad', ['name' => 'Current reminder team']);
        $futureSquad = $em->createEntity('TeamBoardSquad', ['name' => 'Future reminder team']);
        $editor = $this->getInjectableFactory()->create(AssignmentEditor::class);
        $memberIds = [];

        foreach ([7, 8] as $offset) {
            $member = $em->createEntity('TeamBoardMember', [
                'firstName' => 'Reminder', 'lastName' => (string) $offset,
            ]);
            $memberIds[$offset] = $member->getId();
            $editor->createShadow(
                $member->getId(), $currentSquad->getId(), null, null,
                Position::MEMBER, $this->day(0), null, Status::CONFIRMED, null, Position::DEFAULT_LIST,
            );
            $editor->createShadow(
                $member->getId(), $futureSquad->getId(), null, null,
                Position::MEMBER, $this->day($offset), null, Status::DRAFT, null, Position::DEFAULT_LIST,
            );
        }

        $today = $this->day(0);
        $timeline = $this->getInjectableFactory()->create(Service::class)->getTimeline(
            $today, $today, $this->day(9),
        );
        $cards = [];
        foreach ($timeline->teams as $team) {
            if ($team->boardSquadId !== $currentSquad->getId()) {
                continue;
            }
            foreach ($team->members as $card) {
                $cards[$card->boardMemberId] = $card;
            }
        }

        $this->assertArrayHasKey($memberIds[7], $cards);
        $this->assertArrayHasKey($memberIds[8], $cards);
        $this->assertSame(Status::CONFIRMED, $cards[$memberIds[7]]->status);
        $this->assertContains('draftDue', $cards[$memberIds[7]]->warnings);
        $this->assertSame([], $cards[$memberIds[8]]->warnings);
    }
}
