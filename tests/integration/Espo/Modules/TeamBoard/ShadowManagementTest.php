<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Shadow\ManagementService;
use Espo\Modules\TeamBoard\Tools\Shadow\PhotoService;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\Modules\TeamBoard\Entities\TeamBoardSquadVisibility;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use tests\integration\Core\BaseTestCase;

class ShadowManagementTest extends BaseTestCase
{
    private function entityManager(): EntityManager
    {
        return $this->getContainer()->getByClass(EntityManager::class);
    }

    public function testBoardOnlyRecordsCanBeCreatedEditedAndArchived(): void
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $service->createMember((object) ['name' => 'External Expert']);
        $service->createSquad((object) [
            'name' => 'Temporary Cell',
            'positionList' => ['Lead', 'Specialist'],
            'colourKey' => 'teal',
        ]);

        $member = $this->entityManager()->getRDBRepository('TeamBoardMember')
            ->where(['name' => 'External Expert'])
            ->findOne();
        $squad = $this->entityManager()->getRDBRepository('TeamBoardSquad')
            ->where(['name' => 'Temporary Cell'])
            ->findOne();
        $this->assertNotNull($member);
        $this->assertNotNull($squad);

        $service->updateMember($member->getId(), (object) ['name' => 'External Adviser']);
        $service->updateSquad($squad->getId(), (object) [
            'name' => 'Advisory Cell',
            'positionList' => ['Lead', 'Adviser'],
            'colourKey' => 'violet',
        ]);
        $service->archiveMember($member->getId());
        $service->archiveSquad($squad->getId());

        $this->assertSame('External Adviser', $this->entityManager()
            ->getEntityById('TeamBoardMember', $member->getId())?->get('name'));
        $this->assertTrue((bool) $this->entityManager()
            ->getEntityById('TeamBoardMember', $member->getId())?->get('isArchived'));
        $this->assertSame('Advisory Cell', $this->entityManager()
            ->getEntityById('TeamBoardSquad', $squad->getId())?->get('name'));
        $this->assertTrue((bool) $this->entityManager()
            ->getEntityById('TeamBoardSquad', $squad->getId())?->get('isArchived'));
    }

    /**
     * P12 (review №11): archiving a board-only person ends every current
     * period on the archive date and removes future Draft and confirmed plans
     * in the same transaction; past history stays untouched.
     */
    public function testArchivingBoardOnlyPersonEndsCurrentAndRemovesFuturePeriods(): void
    {
        $em = $this->entityManager();
        $today = $this->getContainer()->getByClass(\Espo\Core\Utils\DateTime::class)->getToday()->toString();
        $day = static fn (int $offset): string => (new \DateTimeImmutable($today))
            ->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

        $squadA = $em->createEntity('TeamBoardSquad', ['name' => 'P12 Archive A',
            'positionList' => ['Leader', 'Member'], 'colourKey' => 'slate', 'isArchived' => false]);
        $squadB = $em->createEntity('TeamBoardSquad', ['name' => 'P12 Archive B',
            'positionList' => ['Leader', 'Member'], 'colourKey' => 'teal', 'isArchived' => false]);
        $member = $em->createEntity('TeamBoardMember', ['name' => 'P12 Archived Person',
            'firstName' => 'P12', 'lastName' => 'Archived Person', 'isArchived' => false]);
        $other = $em->createEntity('TeamBoardMember', ['name' => 'P12 Other Person',
            'firstName' => 'P12', 'lastName' => 'Other Person', 'isArchived' => false]);
        $new = fn (array $data) => $em->createEntity('TeamBoardAssignment', $data + [
            'boardMemberId' => $member->getId(), 'position' => 'Member', 'dateTo' => null,
            'status' => 'confirmed',
        ]);

        $past = $new(['boardSquadId' => $squadB->getId(), 'dateFrom' => $day(-60), 'dateTo' => $day(-30)]);
        $current = $new(['boardSquadId' => $squadA->getId(), 'dateFrom' => $day(-30), 'dateTo' => $day(10)]);
        $parallelDraft = $new(['boardSquadId' => $squadB->getId(), 'dateFrom' => $day(-5),
            'status' => 'draft']);
        $startsToday = $new(['boardSquadId' => $squadB->getId(), 'dateFrom' => $today, 'dateTo' => $day(3)]);
        $futureConfirmed = $new(['boardSquadId' => $squadB->getId(), 'dateFrom' => $day(10),
            'supersedesId' => $current->getId(), 'supersededDateTo' => null]);
        $futureDraft = $new(['boardSquadId' => $squadA->getId(), 'dateFrom' => $day(40),
            'position' => 'Leader', 'status' => 'draft']);
        $otherCurrent = $em->createEntity('TeamBoardAssignment', ['boardMemberId' => $other->getId(),
            'boardSquadId' => $squadA->getId(), 'position' => 'Member', 'dateFrom' => $day(-30),
            'dateTo' => null, 'status' => 'confirmed']);

        $this->getInjectableFactory()->create(ManagementService::class)->archiveMember($member->getId());

        $stored = fn (Entity $e) => $em->getEntityById('TeamBoardAssignment', $e->getId());

        $this->assertTrue((bool) $em->getEntityById('TeamBoardMember', $member->getId())?->get('isArchived'));
        $this->assertSame($day(-30), $stored($past)?->get('dateTo'), 'Past history must stay intact.');
        $this->assertSame($day(-60), $stored($past)?->get('dateFrom'));
        $this->assertSame($today, $stored($current)?->get('dateTo'), 'Current period ends on the archive date.');
        $this->assertSame($day(-30), $stored($current)?->get('dateFrom'));
        $this->assertSame($today, $stored($parallelDraft)?->get('dateTo'));
        $this->assertNull($stored($startsToday), 'A period that has not started before the archive date is removed.');
        $this->assertNull($stored($futureConfirmed), 'Future confirmed plan is removed.');
        $this->assertNull($stored($futureDraft), 'Future Draft plan is removed.');
        $this->assertNull($stored($otherCurrent)?->get('dateTo'), 'Other people are not touched.');

        // Archiving again is a safe no-op and does not rewrite the ended period.
        $this->getInjectableFactory()->create(ManagementService::class)->archiveMember($member->getId());
        $this->assertSame($today, $stored($current)?->get('dateTo'));
        $this->assertSame($day(-30), $stored($past)?->get('dateTo'));
    }

    /**
     * P12 (review №11): a failure after future plans were already removed
     * and a current period was already ended rolls back everything, so the
     * archive flag, the ended periods and the removals never diverge.
     */
    public function testArchiveFailureRollsBackRemovalsEndingsAndFlagTogether(): void
    {
        $em = $this->entityManager();
        $today = $this->getContainer()->getByClass(\Espo\Core\Utils\DateTime::class)->getToday()->toString();
        $day = static fn (int $offset): string => (new \DateTimeImmutable($today))
            ->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

        $squad = $em->createEntity('TeamBoardSquad', ['name' => 'P12 Rollback A',
            'positionList' => ['Leader', 'Member'], 'colourKey' => 'slate', 'isArchived' => false]);
        $member = $em->createEntity('TeamBoardMember', ['name' => 'P12 Rollback Person',
            'firstName' => 'P12', 'lastName' => 'Rollback Person', 'isArchived' => false]);
        $new = fn (array $data) => $em->createEntity('TeamBoardAssignment', $data + [
            'boardMemberId' => $member->getId(), 'boardSquadId' => $squad->getId(),
            'position' => 'Member', 'dateTo' => null, 'status' => 'confirmed',
        ]);
        $current = $new(['dateFrom' => $day(-20), 'dateTo' => $day(15)]);
        $futureConfirmed = $new(['dateFrom' => $day(15), 'position' => 'Leader']);
        $futureDraft = $new(['dateFrom' => $day(30), 'status' => 'draft']);

        $editor = $this->getInjectableFactory()->create(ArchiveFailureInjectingEditor::class);
        $service = $this->getInjectableFactory()->createWith(ManagementService::class, [
            'assignmentEditor' => $editor,
        ]);

        try {
            $service->archiveMember($member->getId());
            $this->fail('The injected failure must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame(ArchiveFailureInjectingEditor::MESSAGE, $e->getMessage());
        }

        // The failure struck after both deletions and one ending were written.
        $this->assertSame(2, $editor->deleted);
        $this->assertSame(1, $editor->closed);

        $stored = fn (Entity $e) => $em->getEntityById('TeamBoardAssignment', $e->getId());

        $this->assertFalse((bool) $em->getEntityById('TeamBoardMember', $member->getId())?->get('isArchived'));
        $this->assertSame($day(15), $stored($current)?->get('dateTo'), 'Ending is rolled back.');
        $this->assertNotNull($stored($futureConfirmed), 'Removal of future confirmed is rolled back.');
        $this->assertNotNull($stored($futureDraft), 'Removal of future Draft is rolled back.');

        // A later normal archive still completes the whole change.
        $this->getInjectableFactory()->create(ManagementService::class)->archiveMember($member->getId());
        $this->assertTrue((bool) $em->getEntityById('TeamBoardMember', $member->getId())?->get('isArchived'));
        $this->assertSame($today, $stored($current)?->get('dateTo'));
        $this->assertNull($stored($futureConfirmed));
        $this->assertNull($stored($futureDraft));
    }

    public function testAssignmentEditWithoutBoardReadCannotWriteBeforeReturningForbidden(): void
    {
        $em = $this->entityManager();
        $member = $em->createEntity('TeamBoardMember', ['name' => 'Read gate', 'note' => 'Original note']);
        $user = $this->createUser('missing.board.read', ['data' => [
            'TeamBoard' => false,
            'TeamBoardAssignment' => ['read' => 'all', 'edit' => 'all', 'create' => 'yes', 'delete' => 'all'],
        ]]);
        $this->authenticate($user->getUserName());
        try {
            $this->getInjectableFactory()->create(ManagementService::class)
                ->updateMember($member->getId(), (object) ['note' => 'Unauthorized change']);
            $this->fail('Missing board access did not reject the write.');
        } catch (\Espo\Core\Exceptions\Forbidden) {
            $stored = $em->getRDBRepository('TeamBoardMember')->where(['id' => $member->getId()])->findOne();
            $this->assertSame('Original note', $stored->get('note'));
        }
    }

    public function testBoardOnlyNoteCanBeUpdatedAndClearedWithoutRenamingMember(): void
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $service->createMember((object) [
            'firstName' => 'Note',
            'lastName' => 'Owner',
            'note' => 'Initial note',
        ]);

        $member = $this->entityManager()->getRDBRepository('TeamBoardMember')
            ->where(['name' => 'Note Owner'])
            ->findOne();
        $this->assertNotNull($member);

        $service->updateMember($member->getId(), (object) ['note' => 'Updated note']);
        $this->assertSame('Updated note', $this->entityManager()
            ->getEntityById('TeamBoardMember', $member->getId())?->get('note'));

        $service->updateMember($member->getId(), (object) ['note' => '']);
        $this->assertSame('', $this->entityManager()
            ->getEntityById('TeamBoardMember', $member->getId())?->get('note'));
    }

    public function testEachNamePartCanBeSavedAloneWithoutLosingTheOther(): void
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $service->createMember((object) [
            'firstName' => 'Partial',
            'lastName' => 'Original',
            'note' => 'Kept note',
        ]);

        $member = $this->entityManager()->getRDBRepository('TeamBoardMember')
            ->where(['name' => 'Partial Original'])
            ->findOne();
        $this->assertNotNull($member);

        $service->updateMember($member->getId(), (object) ['lastName' => 'Renamed']);

        $stored = $this->entityManager()->getEntityById('TeamBoardMember', $member->getId());
        $this->assertSame('Partial', $stored?->get('firstName'));
        $this->assertSame('Renamed', $stored?->get('lastName'));
        $this->assertSame('Partial Renamed', $stored?->get('name'));
        $this->assertSame('Kept note', $stored?->get('note'));

        $service->updateMember($member->getId(), (object) ['firstName' => 'Other']);

        $stored = $this->entityManager()->getEntityById('TeamBoardMember', $member->getId());
        $this->assertSame('Other', $stored?->get('firstName'));
        $this->assertSame('Renamed', $stored?->get('lastName'));
        $this->assertSame('Other Renamed', $stored?->get('name'));
        $this->assertSame('Kept note', $stored?->get('note'));
    }

    public function testAPartialNameUpdateStillRejectsAnEmptyOrOversizedValue(): void
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $service->createMember((object) ['firstName' => 'Guarded', 'lastName' => 'Member']);

        $member = $this->entityManager()->getRDBRepository('TeamBoardMember')
            ->where(['name' => 'Guarded Member'])
            ->findOne();
        $this->assertNotNull($member);

        foreach ([
            (object) ['firstName' => ''],
            (object) ['firstName' => str_repeat('a', 151)],
            (object) ['lastName' => str_repeat('b', 101)],
        ] as $payload) {
            try {
                $service->updateMember($member->getId(), $payload);
                $this->fail('An invalid partial name update was accepted.');
            } catch (BadRequest) {
            }
        }

        $stored = $this->entityManager()->getEntityById('TeamBoardMember', $member->getId());
        $this->assertSame('Guarded', $stored?->get('firstName'));
        $this->assertSame('Member', $stored?->get('lastName'));
        $this->assertSame('Guarded Member', $stored?->get('name'));
    }

    public function testNameThatOverflowsTheColumnsIsRefusedWithALocalizedBadRequest(): void
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $service->createMember((object) ['firstName' => 'Fits', 'lastName' => 'Columns']);
        $member = $this->entityManager()->getRDBRepository('TeamBoardMember')
            ->where(['name' => 'Fits Columns'])
            ->findOne();
        $this->assertNotNull($member);
        $before = $this->entityManager()->getRDBRepository('TeamBoardMember')->count();

        $create = [
            (object) ['firstName' => str_repeat('a', 100), 'lastName' => str_repeat('b', 100)],
            (object) ['firstName' => str_repeat('a', 120), 'lastName' => 'X'],
            (object) ['name' => str_repeat('c', 150)],
        ];
        foreach ($create as $payload) {
            try {
                $service->createMember($payload);
                $this->fail('An oversized name was accepted.');
            } catch (BadRequest $e) {
                $body = json_decode((string) $e->getBody(), true);
                $this->assertSame('bad_nameLength', $body['messageTranslation']['label']);
                $this->assertSame('TeamBoard', $body['messageTranslation']['scope']);
                $this->assertSame(1, preg_match('/^[ -~]+$/', $e->getMessage()));
            }
        }

        foreach ([
            (object) ['firstName' => str_repeat('a', 100), 'lastName' => str_repeat('b', 100)],
            (object) ['lastName' => str_repeat('b', 143)],
            (object) ['name' => str_repeat('d', 150)],
        ] as $payload) {
            try {
                $service->updateMember($member->getId(), $payload);
                $this->fail('An oversized name was accepted on edit.');
            } catch (BadRequest $e) {
                $this->assertSame(
                    'bad_nameLength',
                    json_decode((string) $e->getBody(), true)['messageTranslation']['label'],
                );
            }
        }

        $this->assertSame($before, $this->entityManager()->getRDBRepository('TeamBoardMember')->count());
        $stored = $this->entityManager()->getEntityById('TeamBoardMember', $member->getId());
        $this->assertSame('Fits Columns', $stored?->get('name'));

        $service->createMember((object) ['firstName' => str_repeat('a', 100), 'lastName' => str_repeat('b', 49)]);
        $this->assertSame($before + 1, $this->entityManager()->getRDBRepository('TeamBoardMember')->count());
    }

    public function testAClearedNoteArrivingAsNullIsStoredAsAnEmptyNote(): void
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $service->createMember((object) [
            'firstName' => 'Null',
            'lastName' => 'Note',
            'note' => 'Initial note',
        ]);

        $member = $this->entityManager()->getRDBRepository('TeamBoardMember')
            ->where(['name' => 'Null Note'])
            ->findOne();
        $this->assertNotNull($member);

        $service->updateMember($member->getId(), (object) ['note' => null]);

        $stored = $this->entityManager()->getEntityById('TeamBoardMember', $member->getId());
        $this->assertSame('', $stored?->get('note'));
        $this->assertSame('Null Note', $stored?->get('name'));
    }

    public function testBoardOnlyPhotoCanBeAttachedAndRemoved(): void
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $service->createMember((object) ['name' => 'Photo Owner']);

        $member = $this->entityManager()->getRDBRepository('TeamBoardMember')
            ->where(['name' => 'Photo Owner'])
            ->findOne();
        $this->assertNotNull($member);

        $photo = $this->getInjectableFactory()->create(PhotoService::class);
        $attachResponse = $photo->attach($member->getId(), (object) [
            'name' => 'avatar.png',
            'type' => 'image/png',
            'file' => 'data:image/png;base64,' .
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ]);

        $withPhoto = $this->entityManager()->getEntityById('TeamBoardMember', $member->getId());
        $this->assertNotNull($withPhoto?->get('photoId'));
        $this->assertSame($withPhoto?->get('photoId'), $attachResponse->boardPhotoId);

        $removeResponse = $photo->remove($member->getId());

        $withoutPhoto = $this->entityManager()->getEntityById('TeamBoardMember', $member->getId());
        $this->assertNull($withoutPhoto?->get('photoId'));
        $this->assertNull($removeResponse->boardPhotoId);
    }

    public function testUnreadableLinkedMemberCannotBeChangedByKnownId(): void
    {
        $em = $this->entityManager();
        $target = $this->createUser('r1.hidden.target');
        $member = $this->getInjectableFactory()->create(Registry::class)->memberForUser($target->getId());
        $member->set('note', 'Original note');
        $em->saveEntity($member);

        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $this->getInjectableFactory()->create(PhotoService::class)->attach($member->getId(), (object) [
            'name' => 'original.png', 'type' => 'image/png', 'file' => 'data:image/png;base64,' . $png,
        ]);
        $photoId = $em->getEntityById('TeamBoardMember', $member->getId())?->get('photoId');
        $this->assertNotNull($photoId);

        foreach (['own', 'team', 'no'] as $userRead) {
            $editor = $this->createUser('r1.editor.' . $userRead, ['data' => [
                'TeamBoard' => true,
                'TeamBoardAssignment' => ['read' => 'all', 'edit' => 'all', 'create' => 'yes'],
                'User' => ['read' => $userRead],
            ]]);
            $this->authenticate($editor->getUserName());
            $management = $this->getInjectableFactory()->create(ManagementService::class);
            $photo = $this->getInjectableFactory()->create(PhotoService::class);

            foreach ([
                fn () => $management->updateMember($member->getId(), (object) ['note' => 'Changed']),
                fn () => $photo->attach($member->getId(), (object) [
                    'name' => 'replacement.png', 'type' => 'image/png',
                    'file' => 'data:image/png;base64,' . $png,
                ]),
                fn () => $photo->remove($member->getId()),
            ] as $mutation) {
                try {
                    $mutation();
                    self::fail("Known-ID mutation was allowed with User read={$userRead}.");
                } catch (Forbidden) {
                    // A denied target must be rejected before note or photo writes.
                }

                $stored = $em->getEntityById('TeamBoardMember', $member->getId());
                $this->assertSame('Original note', $stored?->get('note'));
                $this->assertSame($photoId, $stored?->get('photoId'));
                $this->assertNotNull($em->getEntityById('Attachment', $photoId));
                $this->assertCount(1, iterator_to_array($em->getRDBRepository('Attachment')
                    ->where(['relatedType' => 'TeamBoardMember', 'relatedId' => $member->getId(), 'field' => 'photo'])
                    ->find()));
            }
        }
    }

    public function testEditorCanChangeOwnLinkedBoardDataWithoutUserEditPermission(): void
    {
        $editor = $this->createUser('r1.own.editor', ['data' => [
            'TeamBoard' => true,
            'TeamBoardAssignment' => ['read' => 'all', 'edit' => 'all', 'create' => 'yes'],
            'User' => ['read' => 'own', 'edit' => 'no'],
        ]]);
        $member = $this->getInjectableFactory()->create(Registry::class)->memberForUser($editor->getId());
        $this->authenticate($editor->getUserName());

        $this->getInjectableFactory()->create(ManagementService::class)
            ->updateMember($member->getId(), (object) ['note' => 'Own board note']);
        $this->assertSame('Own board note', $this->entityManager()
            ->getEntityById('TeamBoardMember', $member->getId())?->get('note'));

        $photo = $this->getInjectableFactory()->create(PhotoService::class);
        $photo->attach($member->getId(), (object) [
            'name' => 'own.png', 'type' => 'image/png',
            'file' => 'data:image/png;base64,' .
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ]);
        $this->assertNotNull($this->entityManager()
            ->getEntityById('TeamBoardMember', $member->getId())?->get('photoId'));
        $photo->remove($member->getId());
        $this->assertNull($this->entityManager()
            ->getEntityById('TeamBoardMember', $member->getId())?->get('photoId'));
    }

    public function testPortalUserCannotChangeBoardOnlyMemberByKnownId(): void
    {
        $em = $this->entityManager();
        $member = $em->createEntity('TeamBoardMember', [
            'name' => 'Portal denied target', 'note' => 'Original',
        ]);
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $this->getInjectableFactory()->create(PhotoService::class)->attach($member->getId(), (object) [
            'name' => 'original.png', 'type' => 'image/png', 'file' => 'data:image/png;base64,' . $png,
        ]);
        $photoId = $em->getEntityById('TeamBoardMember', $member->getId())?->get('photoId');
        $this->assertNotNull($photoId);
        $portal = $this->createUser('r1.portal', ['data' => [
            'TeamBoard' => true,
            'TeamBoardAssignment' => ['read' => 'all', 'edit' => 'all'],
            'User' => ['read' => 'all'],
        ]], true);
        // Native portal login needs a portal application. Inject that identity
        // to test the service boundary independently of login setup.
        $management = $this->getInjectableFactory()->createWith(ManagementService::class, ['user' => $portal]);
        $photo = $this->getInjectableFactory()->createWith(PhotoService::class, ['user' => $portal]);
        foreach ([
            fn () => $management->updateMember($member->getId(), (object) ['note' => 'Changed']),
            fn () => $photo->attach($member->getId(), (object) [
                'name' => 'replacement.png', 'type' => 'image/png',
                'file' => 'data:image/png;base64,' . $png,
            ]),
            fn () => $photo->remove($member->getId()),
        ] as $mutation) {
            try {
                $mutation();
                self::fail('Portal user changed board-only data.');
            } catch (Forbidden) {
                $stored = $em->getEntityById('TeamBoardMember', $member->getId());
                $this->assertSame('Original', $stored?->get('note'));
                $this->assertSame($photoId, $stored?->get('photoId'));
                $this->assertNotNull($em->getEntityById('Attachment', $photoId));
            }
        }
    }

    public function testUnsafePhotoPayloadsAreRejectedWithoutCreatingPhotoState(): void
    {
        $management = $this->getInjectableFactory()->create(ManagementService::class);
        $management->createMember((object) ['name' => 'Photo Validation']);

        $member = $this->entityManager()->getRDBRepository('TeamBoardMember')
            ->where(['name' => 'Photo Validation'])
            ->findOne();
        $this->assertNotNull($member);

        $photo = $this->getInjectableFactory()->create(PhotoService::class);
        $validPng = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $payloads = [
            [
                'name' => 'avatar.jpg',
                'type' => 'image/jpeg',
                'file' => 'data:image/png;base64,' . $validPng,
            ],
            [
                'name' => 'avatar.svg',
                'type' => 'image/svg+xml',
                'file' => 'data:image/svg+xml;base64,' . base64_encode('<svg></svg>'),
            ],
            [
                'name' => 'avatar.png',
                'type' => 'image/png',
                'file' => 'not-a-data-url',
            ],
            [
                'name' => 'avatar.php',
                'type' => 'image/png',
                'file' => 'data:image/png;base64,' . $validPng,
            ],
            [
                'name' => 'avatar.png.php',
                'type' => 'image/png',
                'file' => 'data:image/png;base64,' . $validPng,
            ],
            [
                'name' => 'avatar.png',
                'type' => 'image/png',
                'file' => 'data:image/png;base64,' . base64_encode('not-an-image'),
            ],
            [
                'name' => 'avatar.png',
                'type' => 'image/png',
                'file' => 'data:image/png;base64,' . base64_encode(str_repeat('x', 5 * 1024 * 1024 + 1)),
            ],
        ];

        foreach ($payloads as $payload) {
            try {
                $photo->attach($member->getId(), (object) $payload);
                self::fail('Unsafe board photo payload was accepted.');
            } catch (BadRequest) {
                // Every rejected payload must leave the member unmodified.
            }

            $this->assertNull($this->entityManager()
                ->getEntityById('TeamBoardMember', $member->getId())?->get('photoId'));
        }
    }

    public function testLinkedProfilesStayReadOnlyAndSystemAvatarBlocksBoardPhoto(): void
    {
        /** @var User $user */
        $user = $this->entityManager()->createEntity(User::ENTITY_TYPE, [
            'userName' => 'shadow.linked',
            'lastName' => 'Linked',
            'type' => User::TYPE_REGULAR,
            'isActive' => true,
            'avatarId' => 'system-avatar-id',
        ]);
        /** @var Team $team */
        $team = $this->entityManager()->createEntity(Team::ENTITY_TYPE, [
            'name' => 'Linked Team',
            'positionList' => ['Leader', 'Member'],
        ]);
        $registry = $this->getInjectableFactory()->create(Registry::class);
        $member = $registry->memberForUser($user->getId());
        $squad = $registry->squadForTeam($team->getId());
        $management = $this->getInjectableFactory()->create(ManagementService::class);

        try {
            $management->updateMember($member->getId(), (object) ['name' => 'Copied Name']);
            $this->fail('Linked User name must not be copied into Team Board.');
        }
        catch (BadRequest) {
            $this->assertNull($member->get('name'));
        }

        try {
            $management->updateSquad($squad->getId(), (object) ['name' => 'Copied Team']);
            $this->fail('Linked Team name must not be copied into Team Board.');
        }
        catch (BadRequest) {
            $this->assertNull($squad->get('name'));
        }

        $this->expectException(BadRequest::class);
        $this->getInjectableFactory()->create(PhotoService::class)->attach(
            $member->getId(),
            (object) [
                'name' => 'unused.png',
                'type' => 'image/png',
                'file' => 'unused-because-system-avatar-wins',
            ],
        );
    }

    public function testArchiveUsesOpenMonthAndRestoreUsesToday(): void
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $service->createSquad((object) [
            'name' => 'Future Cell',
            'positionList' => ['Member'],
            'colourKey' => 'slate',
        ]);
        $squad = $this->entityManager()->getRDBRepository('TeamBoardSquad')
            ->where(['name' => 'Future Cell'])->findOne();
        $this->assertNotNull($squad);

        $service->archiveSquad($squad->getId(), $this->nextMonth(14));
        $this->assertCount(0, $service->archivedSquads($this->thisMonth()));
        $this->assertCount(1, $service->archivedSquads($this->nextMonth(14)));

        $service->restoreSquad($squad->getId(), $this->nextMonth(14));

        $events = $this->entityManager()->getRDBRepository(TeamBoardSquadVisibility::ENTITY_TYPE)
            ->where(['boardSquadId' => $squad->getId()])
            ->find();
        $this->assertCount(1, $events);
        $this->assertSame(date('Y-m-d'), $events[0]->get('effectiveDate'));
        $this->assertSame(TeamBoardSquadVisibility::ACTIVE, $events[0]->get('state'));
        $this->assertCount(0, $service->archivedSquads($this->nextMonth(14)));
    }

    public function testArchiveIsBlockedByCurrentOrFutureAssignments(): void
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $service->createSquad((object) [
            'name' => 'Occupied Future Cell',
            'positionList' => ['Member'],
            'colourKey' => 'slate',
        ]);
        $squad = $this->entityManager()->getRDBRepository('TeamBoardSquad')
            ->where(['name' => 'Occupied Future Cell'])->findOne();
        $this->assertNotNull($squad);
        $this->entityManager()->createEntity('TeamBoardAssignment', [
            'boardSquadId' => $squad->getId(),
            'position' => 'Member',
            'dateFrom' => $this->nextMonth(14),
            'dateTo' => null,
            'status' => 'draft',
        ]);

        $this->expectException(BadRequest::class);
        $service->archiveSquad($squad->getId(), $this->nextMonth(14));
    }

    public function testArchiveAllowsAnAssignmentEndedAtTheMonthBoundary(): void
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $service->createSquad((object) [
            'name' => 'Completed Cell',
            'positionList' => ['Member'],
            'colourKey' => 'slate',
        ]);
        $squad = $this->entityManager()->getRDBRepository('TeamBoardSquad')
            ->where(['name' => 'Completed Cell'])->findOne();
        $this->assertNotNull($squad);
        $this->entityManager()->createEntity('TeamBoardAssignment', [
            'boardSquadId' => $squad->getId(),
            'position' => 'Member',
            'dateFrom' => $this->thisMonth(),
            'dateTo' => $this->nextMonth(0),
            'status' => 'confirmed',
        ]);

        $service->archiveSquad($squad->getId(), $this->nextMonth(14));

        $this->assertCount(1, $service->archivedSquads($this->nextMonth(14)));
    }

    private function freshSquad(string $name): Entity
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $service->createSquad((object) ['name' => $name, 'positionList' => ['Member'], 'colourKey' => 'slate']);
        $squad = $this->entityManager()->getRDBRepository('TeamBoardSquad')
            ->where(['name' => $name])->findOne();
        $this->assertNotNull($squad);

        return $squad;
    }

    private function thisMonth(): string
    {
        return (new \DateTimeImmutable('first day of this month'))->format('Y-m-d');
    }

    private function nextMonth(int $plusDays): string
    {
        return (new \DateTimeImmutable('first day of next month'))->modify("+$plusDays days")->format('Y-m-d');
    }

    private function todayString(): string
    {
        return $this->getContainer()->getByClass(\Espo\Core\Utils\DateTime::class)->getToday()->toString();
    }

    /** C07: archiving after a restore in the same open month takes effect from the month start. */
    public function testArchiveAfterARestoreInTheSameMonthArchivesFromTheMonthStart(): void
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $squad = $this->freshSquad('Restored Then Archived Cell');
        $today = $this->todayString();

        $service->archiveSquad($squad->getId(), $today);
        $service->restoreSquad($squad->getId());
        $service->archiveSquad($squad->getId(), $today);

        $this->assertTrue((bool) $this->entityManager()
            ->getEntityById('TeamBoardSquad', $squad->getId())?->get('isArchived'));
        $this->assertCount(1, array_filter($service->archivedSquads($today),
            static fn (array $item): bool => $item['id'] === $squad->getId()));
        $events = $this->entityManager()->getRDBRepository(TeamBoardSquadVisibility::ENTITY_TYPE)
            ->where(['boardSquadId' => $squad->getId()])->find();
        $this->assertCount(1, $events);
        $this->assertSame(substr($today, 0, 8) . '01', $events[0]->get('effectiveDate'));
        $this->assertSame(TeamBoardSquadVisibility::ARCHIVED, $events[0]->get('state'));
    }

    /** C07: a restore of an already active team does not block a later archive in the same month. */
    public function testArchiveAfterRestoringAnActiveTeamWorks(): void
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $squad = $this->freshSquad('Active Restore Cell');
        $today = $this->todayString();

        $service->restoreSquad($squad->getId());
        $service->archiveSquad($squad->getId(), $today);
        $this->assertTrue((bool) $this->entityManager()
            ->getEntityById('TeamBoardSquad', $squad->getId())?->get('isArchived'));
    }

    /** C07: the blocking rule still applies after a restore in the same month. */
    public function testArchiveAfterARestoreIsStillBlockedByAssignments(): void
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $squad = $this->freshSquad('Restored Occupied Cell');
        $today = $this->todayString();
        $service->archiveSquad($squad->getId(), $today);
        $service->restoreSquad($squad->getId());
        $this->entityManager()->createEntity('TeamBoardAssignment', [
            'boardSquadId' => $squad->getId(), 'position' => 'Member',
            'dateFrom' => $today, 'dateTo' => null, 'status' => 'draft',
        ]);

        try {
            $service->archiveSquad($squad->getId(), $today);
            $this->fail('Archive must be blocked.');
        } catch (BadRequest) {
        }

        $this->assertFalse((bool) $this->entityManager()
            ->getEntityById('TeamBoardSquad', $squad->getId())?->get('isArchived'));
    }

    /** C08: the Archive dialog does not list a team that was restored in the open month. */
    public function testArchiveListOmitsATeamRestoredInTheOpenMonth(): void
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $squad = $this->freshSquad('Restored Listed Cell');
        $today = $this->todayString();

        $service->archiveSquad($squad->getId(), $today);
        $service->restoreSquad($squad->getId());

        $this->assertCount(0, array_filter($service->archivedSquads($today),
            static fn (array $item): bool => $item['id'] === $squad->getId()));
    }

    /** C08 (final-C ec477a52): repeated archive/restore cycles on one day never hit the unique index. */
    public function testRepeatedArchiveRestoreCyclesOnTheSameDayAreIdempotent(): void
    {
        $service = $this->getInjectableFactory()->create(ManagementService::class);
        $squad = $this->freshSquad('Cycled Cell');
        $today = $this->todayString();
        $nextMonth = (new \DateTimeImmutable($today))->modify('first day of next month')->format('Y-m-d');
        $isArchived = fn (): bool => (bool) $this->entityManager()
            ->getEntityById('TeamBoardSquad', $squad->getId())?->get('isArchived');

        for ($cycle = 0; $cycle < 3; $cycle++) {
            $service->archiveSquad($squad->getId(), $today);
            $this->assertTrue($isArchived(), "archive $cycle");
            $service->restoreSquad($squad->getId());
            $this->assertFalse($isArchived(), "restore $cycle");
        }

        // Across dates: a scheduled archive of the next month, restored today, twice.
        for ($cycle = 0; $cycle < 2; $cycle++) {
            $service->archiveSquad($squad->getId(), $nextMonth);
            $service->restoreSquad($squad->getId());
            $this->assertFalse($isArchived(), "cross-date restore $cycle");
        }

        $service->archiveSquad($squad->getId(), $today);
        $this->assertTrue($isArchived(), 'final archive');
    }
}

/** Test double: writes like the real editor, then fails on the first ending. */
class ArchiveFailureInjectingEditor extends \Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor
{
    public const MESSAGE = 'P12 injected archive failure';

    public int $deleted = 0;
    public int $closed = 0;

    public function delete(Entity $assignment): void
    {
        parent::delete($assignment);
        $this->deleted++;
    }

    public function closeAt(Entity $assignment, string $date, ?Entity $successor): void
    {
        parent::closeAt($assignment, $date, $successor);
        $this->closed++;

        throw new \RuntimeException(self::MESSAGE);
    }
}
