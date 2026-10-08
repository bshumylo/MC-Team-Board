<?php

namespace Espo\Modules\TeamBoard\Tools\Timeline;

use Espo\Core\Acl\Table;
use Espo\Core\AclManager;
use Espo\Core\Utils\DateTime;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Language\LanguageFactory;
use Espo\Entities\Notification;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Sends one native notification to every editor who can see the Draft target. */
class DraftReminder
{
    public const DAYS = 7;
    /** Rendered by the registered client item view in the viewer's UI language. */
    public const NOTIFICATION_TYPE = 'TeamBoardDraftReminder';

    public function __construct(
        private EntityManager $entityManager,
        private AssignmentQuery $query,
        private Registry $registry,
        private AclManager $aclManager,
        private DateTime $dateTime,
        private Language $language,
        private LanguageFactory $languageFactory,
    ) {}

    public function notifyUpcoming(string $today): void
    {
        foreach ($this->query->findUpcomingDrafts($today, self::DAYS) as $assignment) {
            $this->notifyForAssignment($assignment, $today);
        }
    }

    /** Safe to call directly after create/update; dates outside the window are ignored. */
    public function notifyForAssignment(Entity $assignment, ?string $today = null): void
    {
        $today ??= $this->dateTime->getToday()->toString();
        $dateFrom = $assignment->get('dateFrom');
        $lastDay = (new \DateTimeImmutable($today))->modify('+' . self::DAYS . ' days')->format('Y-m-d');
        if ($assignment->get('status') !== Status::DRAFT || $assignment->get('appliedAt') !== null ||
            !is_string($dateFrom) || $dateFrom < $today || $dateFrom > $lastDay) {
            return;
        }

        $recipients = [];
        foreach ($this->entityManager->getRDBRepositoryByClass(User::class)->where([
            'type' => [User::TYPE_REGULAR, User::TYPE_ADMIN], 'isActive' => true,
        ])->find() as $candidate) {
            if ($this->canReceive($candidate, $assignment)) {
                $recipients[$candidate->getId()] = $candidate;
            }
        }
        if ($recipients === []) {
            return;
        }

        $this->entityManager->getTransactionManager()->run(function () use (
            $assignment, $today, $lastDay, $recipients
        ): void {
            $fresh = $this->query->lockForUpdate($assignment->getId());
            $dateFrom = $fresh?->get('dateFrom');
            if (!$fresh || $fresh->get('status') !== Status::DRAFT || $fresh->get('appliedAt') !== null ||
                !is_string($dateFrom) || $dateFrom < $today || $dateFrom > $lastDay) {
                return;
            }
            $sent = $fresh->get('reminderUserIds');
            $sent = is_array($sent) ? array_fill_keys(array_filter($sent, 'is_string'), true) : [];
            foreach ($recipients as $recipient) {
                if (isset($sent[$recipient->getId()]) || !$this->canReceive($recipient, $fresh)) {
                    continue;
                }
                $notification = $this->entityManager->getRDBRepositoryByClass(Notification::class)->getNew();
                $member = $this->registry->memberFromInput((string) (
                    $fresh->get('boardMemberId') ?: $fresh->get('memberId')
                ));
                $person = $this->registry->resolveMember($member);
                $preferences = $this->entityManager->getEntityById('Preferences', $recipient->getId());
                $locale = $preferences?->get('language');
                $language = is_string($locale) && $locale !== ''
                    ? $this->languageFactory->create($locale) : $this->language;
                // Native message renderer builds the entity anchor with .text(entityName).
                // Names never enter Markdown or the native message template.
                $message = str_replace('{person}', '{entity}',
                    $language->translateLabel('draftReminderMessage', 'messages', 'TeamBoard'));
                $notification->setType(self::NOTIFICATION_TYPE)
                    ->setUserId($recipient->getId())
                    ->setRelatedType(AssignmentQuery::ENTITY_TYPE)
                    ->setRelatedId($fresh->getId())
                    ->setMessage($message)
                    ->setData([
                        'teamBoardDraftReminder' => true, 'dateFrom' => $dateFrom,
                        'entityType' => 'TeamBoard', 'entityId' => $member->getId(),
                        'entityName' => $person->name,
                    ]);
                $this->entityManager->saveEntity($notification);
                $sent[$recipient->getId()] = true;
            }
            $fresh->set('reminderUserIds', array_keys($sent));
            $this->entityManager->saveEntity($fresh);
        });
    }

    private function canReceive(User $candidate, Entity $assignment): bool
    {
        if ($candidate->isAdmin()) {
            return true;
        }
        if (!$this->aclManager->checkScope($candidate, 'TeamBoard', Table::ACTION_READ) ||
            !$this->aclManager->checkScope($candidate, AssignmentQuery::ENTITY_TYPE, Table::ACTION_EDIT) ||
            !$this->aclManager->checkEntityRead($candidate, $assignment)) {
            return false;
        }
        $member = $this->registry->memberFromInput((string) (
            $assignment->get('boardMemberId') ?: $assignment->get('memberId')
        ));
        $squad = $this->registry->squadFromInput((string) (
            $assignment->get('boardSquadId') ?: $assignment->get('teamId')
        ));
        $userId = $member->get('userId');
        if (is_string($userId)) {
            $user = $this->entityManager->getRDBRepositoryByClass(User::class)->getById($userId);
            if (!$user || !$this->aclManager->checkEntityRead($candidate, $user)) {
                return false;
            }
        }
        $teamId = $squad->get('teamId');
        if (is_string($teamId)) {
            $team = $this->entityManager->getRDBRepositoryByClass(Team::class)->getById($teamId);
            if (!$team || !$this->aclManager->checkEntityRead($candidate, $team)) {
                return false;
            }
        }
        return true;
    }
}
