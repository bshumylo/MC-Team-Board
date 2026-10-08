<?php

namespace tests\integration\Espo\Modules\TeamBoard;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Database\Helper;
use Espo\Entities\Attachment;
use Espo\Entities\User;
use integration\Core\NoTransaction;
use Espo\Modules\TeamBoard\Hooks\User\RemoveBoardData;
use Espo\Modules\TeamBoard\Tools\Shadow\ManagementService;
use Espo\Modules\TeamBoard\Tools\Shadow\PhotoService;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use tests\integration\Core\BaseTestCase;

class SystemUserDeletionTest extends BaseTestCase
{
    private function entityManager(): EntityManager
    {
        return $this->getContainer()->getByClass(EntityManager::class);
    }

    public function testLinkedUserCannotBeArchivedFromTeamBoard(): void
    {
        $user = $this->createSyntheticUser('board.cleanup.archive-guard');
        $member = $this->getInjectableFactory()->create(Registry::class)
            ->memberForUser($user->getId());

        $this->expectException(BadRequest::class);
        $this->getInjectableFactory()->create(ManagementService::class)
            ->archiveMember($member->getId());
    }

    public function testRemovingLinkedUserRemovesOnlyTheirBoardRecordsAndPhoto(): void
    {
        $owner = $this->createSyntheticUser('board.cleanup.owner');
        $control = $this->createSyntheticUser('board.cleanup.control');
        $registry = $this->getInjectableFactory()->create(Registry::class);
        $ownerMember = $registry->memberForUser($owner->getId());
        $controlMember = $registry->memberForUser($control->getId());
        $ownerAssignment = $this->assignment($owner, $ownerMember);
        $controlAssignment = $this->assignment($control, $controlMember);
        $ownerPhoto = $this->photo($ownerMember);
        $controlPhoto = $this->photo($controlMember);
        $attachmentRepository = $this->entityManager()
            ->getRDBRepositoryByClass(Attachment::class);
        $ownerPhotoPath = $attachmentRepository->getFilePath($ownerPhoto);
        $controlPhotoPath = $attachmentRepository->getFilePath($controlPhoto);
        $this->assertFileExists($ownerPhotoPath);
        $this->assertFileExists($controlPhotoPath);
        $ownerMember->set('photoId', $ownerPhoto->getId());
        $controlMember->set('photoId', $controlPhoto->getId());
        $this->entityManager()->saveEntity($ownerMember);
        $this->entityManager()->saveEntity($controlMember);

        $this->entityManager()->removeEntity($owner);

        $this->assertNull($this->entityManager()->getEntityById('TeamBoardMember', $ownerMember->getId()));
        $this->assertNull($this->entityManager()->getEntityById('TeamBoardAssignment', $ownerAssignment->getId()));
        $this->assertNull($this->entityManager()->getEntityById(Attachment::ENTITY_TYPE, $ownerPhoto->getId()));
        $this->assertFileDoesNotExist($ownerPhotoPath);
        $this->assertNotNull($this->entityManager()->getEntityById('TeamBoardMember', $controlMember->getId()));
        $this->assertNotNull($this->entityManager()->getEntityById('TeamBoardAssignment', $controlAssignment->getId()));
        $this->assertNotNull($this->entityManager()->getEntityById(Attachment::ENTITY_TYPE, $controlPhoto->getId()));
        $this->assertFileExists($controlPhotoPath);

        // The CRM entity is already gone; exercise the cleanup hook itself a
        // second time to prove that a partial/retried cleanup is harmless.
        $this->getInjectableFactory()->create(RemoveBoardData::class)
            ->afterRemove($owner, RemoveOptions::fromAssoc([]));

        $this->assertNotNull($this->entityManager()->getEntityById('TeamBoardMember', $controlMember->getId()));
    }

    #[NoTransaction]
    public function testCleanupRetryFinishesAfterPartialAssignmentCleanup(): void
    {
        $user = $this->createSyntheticUser('board.cleanup.partial-retry');
        $registry = $this->getInjectableFactory()->create(Registry::class);
        $member = $registry->memberForUser($user->getId());
        $assignment = $this->assignment($user, $member);
        $photo = $this->photo($member);
        $photoPath = $this->entityManager()
            ->getRDBRepositoryByClass(Attachment::class)
            ->getFilePath($photo);

        try {
            // Simulate an earlier cleanup phase that removed assignments but
            // stopped before deleting the shadow member and its photo.
            $this->entityManager()->removeEntity($assignment);

            $hook = $this->getInjectableFactory()->create(RemoveBoardData::class);
            $hook->afterRemove($user, RemoveOptions::fromAssoc([]));

            $this->assertNull($this->entityManager()->getEntityById(
                'TeamBoardMember',
                $member->getId(),
            ));
            $this->assertNull($this->entityManager()->getEntityById(
                Attachment::ENTITY_TYPE,
                $photo->getId(),
            ));
            $this->assertFileDoesNotExist($photoPath);

            // A retry after the partial cleanup is a no-op and must not throw.
            $hook->afterRemove($user, RemoveOptions::fromAssoc([]));
            $this->assertNotNull($this->entityManager()->getEntityById(
                User::ENTITY_TYPE,
                $user->getId(),
            ));
        } finally {
            $this->cleanupSyntheticUsersAndAttachments([$user], [$photo->getId()]);
        }
    }

    public function testRemovingLinkedUserRemovesLegacyAssignmentWithoutShadowMember(): void
    {
        $user = $this->createSyntheticUser('board.cleanup.legacy');
        $assignment = $this->entityManager()->createEntity('TeamBoardAssignment', [
            'memberId' => $user->getId(),
            'teamId' => null,
            'boardMemberId' => null,
            'boardSquadId' => null,
            'position' => 'Member',
            'dateFrom' => '2026-09-01',
            'status' => 'confirmed',
        ]);

        $this->entityManager()->removeEntity($user);

        $this->assertNull($this->entityManager()->getEntityById(
            'TeamBoardAssignment',
            $assignment->getId(),
        ));
    }

    public function testDeactivatingLinkedUserKeepsBoardRecords(): void
    {
        $user = $this->createSyntheticUser('board.cleanup.deactivated');
        $member = $this->getInjectableFactory()->create(Registry::class)
            ->memberForUser($user->getId());
        $assignment = $this->assignment($user, $member);

        $user->set('isActive', false);
        $this->entityManager()->saveEntity($user);

        $this->assertFalse($this->entityManager()
            ->getEntityById(User::ENTITY_TYPE, $user->getId())?->get('isActive'));
        $this->assertNotNull($this->entityManager()->getEntityById(
            'TeamBoardMember',
            $member->getId(),
        ));
        $this->assertNotNull($this->entityManager()->getEntityById(
            'TeamBoardAssignment',
            $assignment->getId(),
        ));
    }

    public function testRemovingMissingPhotoIsIdempotent(): void
    {
        $user = $this->createSyntheticUser('board.cleanup.no-photo');
        $member = $this->getInjectableFactory()->create(Registry::class)
            ->memberForUser($user->getId());

        $response = $this->getInjectableFactory()->create(PhotoService::class)
            ->remove($member->getId());

        $this->assertNull($response->boardPhotoId);
        $this->assertNull($this->entityManager()
            ->getEntityById('TeamBoardMember', $member->getId())?->get('photoId'));
    }

    public function testRemovingLinkedUserKeepsPhotoSharedByAnotherBoardMember(): void
    {
        $owner = $this->createSyntheticUser('board.cleanup.shared-owner');
        $control = $this->createSyntheticUser('board.cleanup.shared-control');
        $registry = $this->getInjectableFactory()->create(Registry::class);
        $ownerMember = $registry->memberForUser($owner->getId());
        $controlMember = $registry->memberForUser($control->getId());
        $photo = $this->photo($ownerMember);
        $photoPath = $this->entityManager()
            ->getRDBRepositoryByClass(Attachment::class)
            ->getFilePath($photo);

        $ownerMember->set('photoId', $photo->getId());
        $controlMember->set('photoId', $photo->getId());
        $this->entityManager()->saveEntity($ownerMember);
        $this->entityManager()->saveEntity($controlMember);

        $this->entityManager()->removeEntity($owner);

        $this->assertNull($this->entityManager()->getEntityById(
            'TeamBoardMember',
            $ownerMember->getId(),
        ));
        $remainingControl = $this->entityManager()->getEntityById(
            'TeamBoardMember',
            $controlMember->getId(),
        );
        $this->assertNotNull($remainingControl);
        $this->assertSame($photo->getId(), $remainingControl->get('photoId'));
        $this->assertNotNull($this->entityManager()->getEntityById(
            Attachment::ENTITY_TYPE,
            $photo->getId(),
        ));
        $this->assertFileExists($photoPath);
    }

    #[NoTransaction]
    public function testReplacingPhotoKeepsOldPhotoSharedByAnotherBoardMember(): void
    {
        $owner = $this->createSyntheticUser('board.cleanup.replace-owner');
        $control = $this->createSyntheticUser('board.cleanup.replace-control');
        $attachmentIds = [];

        try {
            $registry = $this->getInjectableFactory()->create(Registry::class);
            $ownerMember = $registry->memberForUser($owner->getId());
            $controlMember = $registry->memberForUser($control->getId());
            $oldPhoto = $this->photo($ownerMember);
            $attachmentIds[] = $oldPhoto->getId();
            $oldPhotoPath = $this->entityManager()
                ->getRDBRepositoryByClass(Attachment::class)
                ->getFilePath($oldPhoto);
            $ownerMember->set('photoId', $oldPhoto->getId());
            $this->entityManager()->saveEntity($ownerMember);
            $this->setPhotoIdDirectly($controlMember->getId(), $oldPhoto->getId());
            $controlMember->set('photoId', $oldPhoto->getId());
            $this->assertSame(1, $this->entityManager()
                ->getRDBRepository('TeamBoardMember')
                ->where([
                    'photoId' => $oldPhoto->getId(),
                    'id!=' => $ownerMember->getId(),
                ])
                ->count());

            $newPhotoId = $this->getInjectableFactory()->create(PhotoService::class)
                ->attach($ownerMember->getId(), (object) [
                    'name' => 'board-photo-replacement.png',
                    'type' => 'image/png',
                    'file' => 'data:image/png;base64,' .
                        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                ])->boardPhotoId;
            $attachmentIds[] = $newPhotoId;

            $this->assertIsString($newPhotoId);
            $this->assertNotSame($oldPhoto->getId(), $newPhotoId);
            $this->assertNotNull($this->entityManager()->getEntityById(
                Attachment::ENTITY_TYPE,
                $oldPhoto->getId(),
            ));
            $this->assertFileExists($oldPhotoPath);
            $this->assertSame($oldPhoto->getId(), $controlMember->get('photoId'));
        } finally {
            $this->cleanupSyntheticUsersAndAttachments([$owner, $control], $attachmentIds);
        }
    }

    #[NoTransaction]
    public function testRemovingPhotoRejectsForeignPhotoReference(): void
    {
        $owner = $this->createSyntheticUser('board.cleanup.foreign-owner');
        $control = $this->createSyntheticUser('board.cleanup.foreign-control');
        $attachmentIds = [];

        try {
            $registry = $this->getInjectableFactory()->create(Registry::class);
            $ownerMember = $registry->memberForUser($owner->getId());
            $controlMember = $registry->memberForUser($control->getId());
            $photo = $this->photo($controlMember);
            $attachmentIds[] = $photo->getId();
            $photoPath = $this->entityManager()
                ->getRDBRepositoryByClass(Attachment::class)
                ->getFilePath($photo);
            $ownerMember->set('photoId', $photo->getId());
            $controlMember->set('photoId', $photo->getId());
            $this->setPhotoIdDirectly($ownerMember->getId(), $photo->getId());
            $this->assertSame(1, $this->entityManager()
                ->getRDBRepository('TeamBoardMember')
                ->where([
                    'photoId' => $photo->getId(),
                    'id!=' => $ownerMember->getId(),
                ])
                ->count());

            try {
                $this->getInjectableFactory()->create(PhotoService::class)
                    ->remove($ownerMember->getId());
                self::fail('A foreign board photo reference must be rejected.');
            } catch (Forbidden) {
                // The owner and the attachment must remain untouched.
            }

            $this->assertSame($photo->getId(), $ownerMember->get('photoId'));
            $this->assertSame($photo->getId(), $controlMember->get('photoId'));
            $this->assertNotNull($this->entityManager()->getEntityById(
                Attachment::ENTITY_TYPE,
                $photo->getId(),
            ));
            $this->assertFileExists($photoPath);
        } finally {
            $this->cleanupSyntheticUsersAndAttachments([$owner, $control], $attachmentIds);
        }
    }

    /** @param User[] $users @param string[] $attachmentIds */
    private function cleanupSyntheticUsersAndAttachments(array $users, array $attachmentIds): void
    {
        foreach ($users as $user) {
            $current = $this->entityManager()->getEntityById(User::ENTITY_TYPE, $user->getId());

            if ($current !== null) {
                $this->entityManager()->removeEntity($current);
            }
        }

        foreach (array_unique(array_filter($attachmentIds)) as $attachmentId) {
            $attachment = $this->entityManager()
                ->getRDBRepositoryByClass(Attachment::class)
                ->getById($attachmentId);

            if ($attachment !== null) {
                $this->entityManager()->removeEntity($attachment);
            }
        }
    }

    private function setPhotoIdDirectly(string $memberId, string $photoId): void
    {
        $pdo = $this->getInjectableFactory()->create(Helper::class)->getPDO();
        $statement = $pdo->prepare(
            'UPDATE team_board_member SET photo_id = :photoId WHERE id = :memberId',
        );
        $statement->execute([
            'photoId' => $photoId,
            'memberId' => $memberId,
        ]);
    }

    private function createSyntheticUser(string $userName): User
    {
        return $this->entityManager()->createEntity(User::ENTITY_TYPE, [
            'userName' => $userName,
            'lastName' => 'Synthetic',
            'type' => User::TYPE_REGULAR,
            'isActive' => true,
        ]);
    }

    private function assignment(User $user, Entity $member): Entity
    {
        return $this->entityManager()->createEntity('TeamBoardAssignment', [
            'memberId' => $user->getId(),
            'boardMemberId' => $member->getId(),
            'position' => 'Member',
            'dateFrom' => '2026-09-01',
            'status' => 'confirmed',
        ]);
    }

    private function photo(Entity $member): Attachment
    {
        $this->getInjectableFactory()->create(PhotoService::class)->attach(
            $member->getId(),
            (object) [
                'name' => 'board-photo.png',
                'type' => 'image/png',
                'file' => 'data:image/png;base64,' .
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            ],
        );

        $photoId = $this->entityManager()->getEntityById(
            'TeamBoardMember',
            $member->getId(),
        )?->get('photoId');

        $this->assertIsString($photoId);

        $photo = $this->entityManager()
            ->getRDBRepositoryByClass(Attachment::class)
            ->getById($photoId);

        $this->assertNotNull($photo);

        return $photo;
    }
}
