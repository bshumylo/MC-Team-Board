<?php

namespace Espo\Modules\TeamBoard\Hooks\User;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;

/** @implements AfterRemove<Entity> */
class RemoveBoardData implements AfterRemove
{
    public function __construct(private EntityManager $entityManager) {}

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        $userId = $entity->getId();

        if ($userId === '') {
            return;
        }

        foreach ($this->entityManager->getRDBRepository('TeamBoardAssignment')
            ->where(['memberId' => $userId])->find() as $assignment) {
            $this->entityManager->removeEntity($assignment);
        }

        $members = $this->entityManager->getRDBRepository('TeamBoardMember')
            ->where(['userId' => $userId])
            ->find();

        foreach ($members as $member) {
            $memberId = $member->getId();

            foreach ($this->entityManager->getRDBRepository('TeamBoardAssignment')->where([
                'boardMemberId' => $memberId,
            ])->find() as $assignment) {
                $this->entityManager->removeEntity($assignment);
            }

            foreach ($this->entityManager->getRDBRepository('Attachment')->where([
                'relatedType' => 'TeamBoardMember',
                'relatedId' => $memberId,
                'field' => 'photo',
            ])->find() as $attachment) {
                if ($this->isReferencedByAnotherMember($attachment->getId(), $memberId)) {
                    continue;
                }

                $this->entityManager->removeEntity($attachment);
            }

            $this->entityManager->removeEntity($member);
        }
    }

    private function isReferencedByAnotherMember(string $attachmentId, string $memberId): bool
    {
        return $this->entityManager->getRDBRepository('TeamBoardMember')
            ->where([
                'photoId' => $attachmentId,
                'id!=' => $memberId,
            ])
            ->findOne() !== null;
    }
}
