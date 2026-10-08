<?php

namespace Espo\Modules\TeamBoard\Tools\Shadow;

use Espo\Core\Acl;
use Espo\Core\Utils\Language;
use Espo\Modules\TeamBoard\Tools\Support\LocalizedDenial;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\EntityProvider;
use Espo\Entities\Attachment;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Entities\TeamBoardMember;
use Espo\Modules\TeamBoard\Tools\Board\Service as BoardService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

class PhotoService
{
    use LocalizedDenial;

    private const MANAGE_SCOPE = 'TeamBoardAssignment';
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_DIMENSION = 4096;
    private const MIME_EXTENSION_MAP = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
    ];

    public function __construct(
        private Acl $acl,
        private User $user,
        private EntityManager $entityManager,
        private Registry $registry,
        private BoardService $boardService,
        private EntityProvider $entityProvider,
        private Language $language,
    ) {}

    public function attach(string $memberId, stdClass $data): stdClass
    {
        $this->assertCanEdit();
        $this->assertOnlyKeys($data, ['file', 'name', 'type']);

        $member = $this->member($memberId);
        $resolved = $this->registry->resolveMember($member);

        if ($member->get('isArchived')) {
            throw new BadRequest('Archived board member cannot receive a photo.');
        }

        $systemAvatarId = $resolved->systemAvatarId;

        if (is_string($systemAvatarId) && $systemAvatarId !== '') {
            throw new BadRequest('EspoCRM avatar takes precedence; board photo upload is disabled.');
        }

        [$name, $type, $contents] = $this->decodeUpload($data);
        $oldId = $member->get('photoId');

        $this->entityManager->getTransactionManager()->run(
            function () use ($member, $name, $type, $contents, $oldId): void {
                $old = null;
                $preserveOld = false;

                if (is_string($oldId) && $oldId !== '') {
                    $old = $this->entityManager
                        ->getRDBRepositoryByClass(Attachment::class)
                        ->getById($oldId);
                    $preserveOld = $old !== null &&
                        $this->isBoardAttachmentFor($old, $member) &&
                        $this->isReferencedByAnotherMember($old->getId(), $member->getId());

                    if ($preserveOld) {
                        // EspoCRM's file-field saver removes the fetched
                        // attachment when the member photo changes. Clear
                        // that fetched value so a shared file survives.
                        $member->setFetched('photoId', null);
                    }
                }

                $attachment = $this->entityManager
                    ->getRDBRepositoryByClass(Attachment::class)
                    ->getNew();
                $attachment->set([
                    'name' => $name,
                    'type' => $type,
                    'role' => Attachment::ROLE_ATTACHMENT,
                    'relatedType' => TeamBoardMember::ENTITY_TYPE,
                    'relatedId' => $member->getId(),
                    'field' => 'photo',
                    'contents' => $contents,
                ]);
                $this->entityManager->saveEntity($attachment);
                $member->set('photoId', $attachment->getId());
                $this->entityManager->saveEntity($member);

                if ($old &&
                    $old->getId() !== $attachment->getId() &&
                    $this->isBoardAttachmentFor($old, $member) &&
                    !$preserveOld) {
                    $this->entityManager->removeEntity($old);
                }
            }
        );

        $response = $this->boardService->getData();
        $response->boardPhotoId = $member->get('photoId');

        return $response;
    }

    public function remove(string $memberId): stdClass
    {
        $this->assertCanEdit();
        $member = $this->member($memberId);
        $photoId = $member->get('photoId');

        if (!is_string($photoId) || $photoId === '') {
            $response = $this->boardService->getData();
            $response->boardPhotoId = null;

            return $response;
        }

        $attachment = $this->attachment($photoId);

        if (!$this->isBoardAttachmentFor($attachment, $member)) {
            throw $this->forbidden('photoOwner');
        }

        $preserveAttachment = $this->isReferencedByAnotherMember(
            $attachment->getId(),
            $member->getId(),
        );

        $this->entityManager->getTransactionManager()->run(
            function () use ($member, $attachment, $preserveAttachment): void {
                if ($preserveAttachment) {
                    $member->setFetched('photoId', null);
                }

                $member->set('photoId', null);
                $this->entityManager->saveEntity($member);

                if (!$preserveAttachment) {
                    $this->entityManager->removeEntity($attachment);
                }
            }
        );

        $response = $this->boardService->getData();
        $response->boardPhotoId = null;

        return $response;
    }

    private function assertCanEdit(): void
    {
        if ($this->user->isPortal() ||
            (!$this->user->isAdmin() &&
            (!$this->acl->checkScope('TeamBoard', Table::ACTION_READ) ||
                !$this->acl->checkScope(self::MANAGE_SCOPE, Table::ACTION_EDIT)))) {
            throw $this->forbidden('noEditAccess');
        }
    }

    /** @param string[] $allowed */
    private function assertOnlyKeys(stdClass $data, array $allowed): void
    {
        $unexpected = array_diff(array_keys(get_object_vars($data)), $allowed);

        if ($unexpected !== []) {
            throw new BadRequest('Unsupported field: ' . implode(', ', $unexpected) . '.');
        }
    }

    private function member(string $id): Entity
    {
        return $this->entityProvider->get(TeamBoardMember::ENTITY_TYPE, $id);
    }

    private function attachment(string $id): Attachment
    {
        return $this->entityManager->getRDBRepositoryByClass(Attachment::class)->getById($id) ??
            throw new NotFound('Attachment not found.');
    }

    /** @return array{string, string, string} */
    private function decodeUpload(stdClass $data): array
    {
        if (!is_string($data->file ?? null) ||
            !is_string($data->name ?? null) ||
            !is_string($data->type ?? null)) {
            throw new BadRequest('Bad board photo payload.');
        }

        if (!preg_match('/^data:([^;,]+);base64,(.+)$/s', $data->file, $matches)) {
            throw new BadRequest('Bad board photo data URL.');
        }

        $contents = base64_decode($matches[2], true);

        if ($contents === false || $matches[1] !== $data->type) {
            throw new BadRequest('Board photo MIME header does not match the request.');
        }

        $this->assertValidImage($data->name, $data->type, $contents);

        return [basename($data->name), $data->type, $contents];
    }

    private function assertValidImage(string $name, string $type, string $contents): void
    {
        $size = strlen($contents);

        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new BadRequest('Board photo must be between 1 byte and 5 MiB.');
        }

        if (!isset(self::MIME_EXTENSION_MAP[$type])) {
            throw new BadRequest('Unsupported board photo MIME type.');
        }

        $basename = basename($name);
        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

        if (substr_count($basename, '.') !== 1 ||
            !in_array($extension, self::MIME_EXTENSION_MAP[$type], true)) {
            throw new BadRequest('Board photo filename or extension does not match its MIME type.');
        }

        $image = getimagesizefromstring($contents);

        if ($image === false || $image['mime'] !== $type) {
            throw new BadRequest('Board photo cannot be decoded or MIME does not match.');
        }

        if ($image[0] < 1 || $image[1] < 1 ||
            $image[0] > self::MAX_DIMENSION || $image[1] > self::MAX_DIMENSION) {
            throw new BadRequest('Board photo dimensions must not exceed 4096 × 4096.');
        }
    }

    private function isBoardAttachmentFor(Attachment $attachment, Entity $member): bool
    {
        return $attachment->get('relatedType') === TeamBoardMember::ENTITY_TYPE &&
            $attachment->get('relatedId') === $member->getId() &&
            $attachment->get('field') === 'photo';
    }

    private function isReferencedByAnotherMember(string $attachmentId, string $memberId): bool
    {
        foreach ($this->entityManager->getRDBRepository(TeamBoardMember::ENTITY_TYPE)
            ->where(['photoId' => $attachmentId])->find() as $candidate) {
            if ($candidate->getId() !== $memberId) {
                return true;
            }
        }

        return false;
    }
}
