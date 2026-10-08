<?php

namespace Espo\Modules\TeamBoard\Tools\Timeline;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\EntityProvider;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\DateTime;
use Espo\Core\Utils\Language;
use Espo\Modules\TeamBoard\Tools\Support\LocalizedDenial;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Entities\TeamBoardMember;
use Espo\Modules\TeamBoard\Entities\TeamBoardSquad;
use Espo\Modules\TeamBoard\Entities\TeamBoardSquadVisibility;
use Espo\Modules\TeamBoard\Jobs\ApplyDueAssignments;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * ACL-gated timeline facade. Shadow ids are authoritative; linked ids remain
 * additive during the 1.1 compatibility window.
 */
class Service
{
    use LocalizedDenial;

    private const SCOPE = 'TeamBoard';
    private const MANAGE_SCOPE = 'TeamBoardAssignment';

    public function __construct(
        private Acl $acl,
        private User $user,
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private EntityProvider $entityProvider,
        private AssignmentQuery $query,
        private AssignmentEditor $editor,
        private CrmPlacement $placement,
        private DraftReminder $draftReminder,
        private Registry $registry,
        private DateTime $dateTime,
        private CrmStateRecorder $recorder,
        private ApplyDueAssignments $applier,
        private Language $language,
    ) {}

    /** @throws Forbidden */
    public function getTimeline(string $date, string $rangeFrom, string $rangeTo): stdClass
    {
        $this->assertReadable();

        $today = $this->today();
        $allowedUsers = $this->findVisibleUsers();
        $rangeStart = min($date, $today);
        // findInRange uses an exclusive upper bound; include starts on the
        // selected date before applying the strict occupancy check below.
        $rangeEnd = (new \DateTimeImmutable(max($date, $today)))->modify('+1 day')->format('Y-m-d');
        $periods = $this->query->findInRange($rangeStart, $rangeEnd);
        $byId = [];
        foreach (array_merge($periods, $this->query->findActualCoveringDate(min($date, $today))) as $period) {
            $byId[$period->getId()] = $period;
        }
        $periods = array_values($byId);
        $grouped = [];
        $allCovering = [];
        foreach ($periods as $period) {
            $member = $this->memberForAssignment($period);
            if (!$member) {
                continue;
            }
            $userId = $member->get('userId');
            if (is_string($userId) && !isset($allowedUsers[$userId])) {
                continue;
            }
            $grouped[$member->getId()][] = $period;
            $interval = Interval::of((string) $period->get('dateFrom'), $period->get('dateTo'));
            if (!$interval->covers($date)) {
                continue;
            }
            if (is_string($userId) && $period->get('status') !== Status::DRAFT) {
                continue; // CRM facts and confirmed projections are chosen below.
            }
            if ($period->get('status') === Status::DRAFT &&
                ($date < $today || $period->get('dateFrom') < $today ||
                    (is_string($userId) && !$allowedUsers[$userId]->isActive()))) {
                continue;
            }
            if ($date >= $today && $member->get('isArchived')) {
                continue;
            }
            $allCovering[] = $period;
        }
        $reserveUserIds = [];
        foreach ($allowedUsers as $crmUser) {
            $member = $this->registry->memberForUser($crmUser->getId());
            $placement = $this->placement->forUser($crmUser, $member, $grouped[$member->getId()] ?? [], $date, $today);
            if (!$placement) {
                continue;
            }
            if (!$placement->get('teamId') && !$placement->get('boardSquadId')) {
                $reserveUserIds[] = $crmUser->getId();
            } else {
                $allCovering[] = $placement;
            }
        }
        $squads = $this->findSquads($date);
        $squadIds = array_map(fn (Entity $squad) => $squad->getId(), $squads);
        $teamIds = array_values(array_filter(array_map(
            fn (Entity $squad) => $squad->get('teamId'),
            $squads
        ), 'is_string'));
        // U02 plans are separate from U06 occupancy. There is no upper date
        // bound: a distant plan must not disappear from the pending count.
        $pendingDrafts = [];
        $draftDueDates = [];
        $lastReminderDay = (new \DateTimeImmutable($today))->modify('+' . DraftReminder::DAYS . ' days')->format('Y-m-d');
        foreach ($this->entityManager->getRDBRepository(AssignmentQuery::ENTITY_TYPE)->where([
            'status' => Status::DRAFT,
            'dateFrom>=' => min($date, $today),
        ])->find() as $draft) {
            $member = $this->memberForAssignment($draft);
            if (!$member || $member->get('isArchived')) {
                continue;
            }
            $userId = $member->get('userId');
            if (is_string($userId) && (!isset($allowedUsers[$userId]) || !$allowedUsers[$userId]->isActive())) {
                continue;
            }
            $draftDate = $draft->get('dateFrom');
            if ($draft->get('appliedAt') === null && is_string($draftDate) &&
                $draftDate >= $today && $draftDate <= $lastReminderDay &&
                (!isset($draftDueDates[$member->getId()]) || $draftDate < $draftDueDates[$member->getId()])) {
                $draftDueDates[$member->getId()] = $draftDate;
            }
            if (!is_string($draftDate) || $draftDate < $date) {
                continue;
            }
            if ($this->filterForSquads([$draft], $squadIds, $teamIds) === []) {
                continue;
            }
            $pendingDrafts[] = (object) [
                'id' => $userId ?? $member->getId(),
                'dateFrom' => $draft->get('dateFrom'),
            ];
        }
        $allCovering = $this->latestDraftPerMember($allCovering);
        $draftOrigins = $this->draftOrigins($allCovering, $squads);
        // U06: a Draft in effect on the viewed date shows the person once, at
        // the destination; the previous place does not repeat them.
        $allCovering = array_values(array_filter(
            $allCovering,
            fn (Entity $assignment): bool => $assignment->get('status') === Status::DRAFT ||
                !array_key_exists((string) $this->memberForAssignment($assignment)?->getId(), $draftOrigins)
        ));
        $covering = $this->filterForSquads($allCovering, $squadIds, $teamIds);
        $assignedBoardMemberIds = array_fill_keys(array_keys($draftOrigins), true);
        // Occupancy is determined before team visibility filtering. An
        // inaccessible team is not the same thing as an absent assignment.
        foreach ($allCovering as $assignment) {
            if ($assignment->get('status') === Status::CONFIRMED) {
                $member = $this->memberForAssignment($assignment);
                if ($member) {
                    $assignedBoardMemberIds[$member->getId()] = true;
                }
            }
        }
        $teamList = [];

        foreach ($squads as $squad) {
            $resolvedSquad = $this->registry->resolveSquad($squad);
            $members = [];

            foreach ($covering as $assignment) {
                if (!$this->assignmentBelongsToSquad($assignment, $squad)) {
                    continue;
                }

                $member = $this->memberForAssignment($assignment);

                if (!$member) {
                    continue;
                }

                $resolvedMember = $this->registry->resolveMember($member);

                if ($resolvedMember->isEspoUser && !isset($allowedUsers[$resolvedMember->userId])) {
                    continue;
                }

                $legacyMemberId = $resolvedMember->userId;

                $members[] = (object) [
                    'id' => $legacyMemberId ?? $member->getId(),
                    'boardMemberId' => $member->getId(),
                    'userId' => $legacyMemberId,
                    'isEspoUser' => $resolvedMember->isEspoUser,
                    'name' => $resolvedMember->name,
                    'firstName' => $resolvedMember->firstName ?? null,
                    'lastName' => $resolvedMember->lastName ?? null,
                    'boardNote' => $member->get('note') ?? '',
                    'userName' => $legacyMemberId !== null
                        ? ($allowedUsers[$legacyMemberId]->getUserName() ?? null)
                        : null,
                    'systemAvatarId' => $resolvedMember->systemAvatarId,
                    'boardPhotoId' => $resolvedMember->boardPhotoId,
                    'avatarId' => $resolvedMember->avatarId,
                    'avatarColor' => $resolvedMember->avatarColor,
                    'position' => $assignment->get('position'),
                    'assignmentId' => CrmPlacement::isSnapshotId($assignment->getId()) ? null : $assignment->getId(),
                    'status' => $assignment->get('status'),
                    'dateFrom' => FactualPeriod::displayStart($assignment),
                    'dateTo' => FactualPeriod::displayEnd($assignment, $today),
                    'note' => $member->get('note') ?? '',
                    'zone' => Interval::of(
                        FactualPeriod::displayStart($assignment),
                        FactualPeriod::displayEnd($assignment, $today)
                    )->zoneAt($this->today()),
                    'warnings' => isset($draftDueDates[$member->getId()]) ? ['draftDue'] : [],
                    'draftDueDate' => $draftDueDates[$member->getId()] ?? null,
                    'draftOrigin' => $assignment->get('status') === Status::DRAFT
                        ? $this->draftOriginFor($draftOrigins[$member->getId()] ?? null, $squad)
                        : null,
                ];
            }

            $teamList[] = (object) [
                'id' => $resolvedSquad->teamId ?? $squad->getId(),
                'boardSquadId' => $squad->getId(),
                'teamId' => $resolvedSquad->teamId,
                'isEspoTeam' => $resolvedSquad->isEspoTeam,
                'name' => $resolvedSquad->name,
                'positionList' => $resolvedSquad->positionList,
                'colourKey' => $resolvedSquad->colourKey,
                'isArchived' => $resolvedSquad->isArchived,
                'members' => $members,
            ];
        }

        $rangeById = [];
        foreach (array_merge(
            $this->query->findInRange($rangeFrom, $rangeTo),
            $this->query->findActualInRange($rangeFrom, $rangeTo),
        ) as $assignment) {
            $rangeById[$assignment->getId()] = $assignment;
        }
        $rangeAssignments = array_values(array_filter(
            $rangeById,
            function (Entity $assignment) use ($allowedUsers, $squadIds, $teamIds, $rangeFrom, $rangeTo): bool {
                if (!Interval::of(
                    FactualPeriod::displayStart($assignment), FactualPeriod::displayEnd($assignment, $this->today())
                )->overlaps(Interval::of($rangeFrom, $rangeTo))) {
                    return false;
                }
                $member = $this->memberForAssignment($assignment);
                if (!$member || (is_string($member->get('userId')) && !isset($allowedUsers[$member->get('userId')]))) {
                    return false;
                }
                return $this->isUnassignedCrmFact($assignment) ||
                    $this->filterForSquads([$assignment], $squadIds, $teamIds) !== [];
            }
        ));

        return (object) [
            'date' => $date,
            'canManage' => $this->canManage(),
            'canEditLive' => $this->user->isAdmin(),
            'positionList' => TeamBoardSquad::DEFAULT_POSITION_LIST,
            'teams' => $teamList,
            'pendingDrafts' => $pendingDrafts,
            'reserve' => $this->findReserve(array_keys($assignedBoardMemberIds), $allowedUsers, $reserveUserIds, $date, $draftDueDates),
            'assignments' => array_map(
                fn (Entity $entity) => $this->toPlain($entity),
                $rangeAssignments
            ),
        ];
    }

    /** @throws BadRequest|Forbidden */
    public function createAssignment(stdClass $data): stdClass
    {
        $boardMemberId = property_exists($data, 'boardMemberId')
            ? $this->requireString($data, 'boardMemberId')
            : $this->requireString($data, 'memberId');
        $boardSquadId = property_exists($data, 'boardSquadId')
            ? $this->requireString($data, 'boardSquadId')
            : $this->requireString($data, 'teamId');
        $position = $this->requireString($data, 'position');
        $dateFrom = $this->requireString($data, 'dateFrom');
        $dateTo = $this->optionalString($data, 'dateTo');
        $status = $this->optionalString($data, 'status') ?? Status::DRAFT;

        $this->assertStatusAndInterval($status, $dateFrom, $dateTo);
        [$member, $squad, $resolvedSquad] = $this->assertWritable($boardMemberId, $boardSquadId);

        if (!in_array($position, $resolvedSquad->positionList, true)) {
            throw new BadRequest('Not allowed position.');
        }

        $interval = Interval::of($dateFrom, $dateTo);

        if (!Zone::mayCreate($interval, $this->today(), $this->user->isAdmin())) {
            throw $this->forbidden('periodZone');
        }

        $assignment = $this->editor->createShadow(
            $member->getId(),
            $squad->getId(),
            is_string($member->get('userId')) ? $member->get('userId') : null,
            is_string($squad->get('teamId')) ? $squad->get('teamId') : null,
            $position,
            $dateFrom,
            $dateTo,
            $status,
            null,
            $resolvedSquad->positionList,
        );
        $this->applyIfDue($assignment);
        $this->draftReminder->notifyForAssignment($assignment, $this->today());

        return $this->refreshed($data->viewDate ?? $dateFrom);
    }

    /**
     * T06: a confirmed assignment applies from its date D. One that is already
     * due when saved (only an administrator may create or change such a
     * period, see Zone) takes effect now, not at the next scheduled run.
     */
    private function applyIfDue(Entity $assignment): void
    {
        if ($assignment->get('status') !== Status::CONFIRMED || $assignment->get('appliedAt') !== null ||
            (string) $assignment->get('dateFrom') > $this->today()) {
            return;
        }

        $this->applier->applyNow($assignment->getId(), $this->user);
    }

    /** @throws BadRequest|Forbidden|NotFound */
    public function updateAssignment(string $id, stdClass $data): stdClass
    {
        $assignment = $this->getAssignment($id);
        $memberInput = (string) ($assignment->get('boardMemberId') ?: $assignment->get('memberId'));
        $squadInput = property_exists($data, 'boardSquadId')
            ? $this->requireString($data, 'boardSquadId')
            : ($this->optionalString($data, 'teamId') ??
                (string) ($assignment->get('boardSquadId') ?: $assignment->get('teamId')));
        $position = $this->optionalString($data, 'position') ?? (string) $assignment->get('position');
        $dateFrom = $this->optionalString($data, 'dateFrom') ?? (string) $assignment->get('dateFrom');
        $dateTo = property_exists($data, 'dateTo')
            ? $this->optionalString($data, 'dateTo')
            : $assignment->get('dateTo');
        $status = $this->optionalString($data, 'status') ?? (string) $assignment->get('status');
        // The only editable note belongs to the person. Keep any old stored
        // assignment value intact, but ignore assignment-note API input.
        $note = $assignment->get('note');

        // Closing a period on the exact date it started (e.g. dragging a
        // just-opened team membership straight to Reserve) asks for a
        // zero-length window. Such a window never covers any date, so it is
        // treated as a removal rather than rejected outright (T16/T20).
        $closingSameDay = $dateTo !== null && $dateTo === $dateFrom;

        $this->assertStatusAndInterval($status, $dateFrom, $dateTo, $closingSameDay);
        [, $squad, $resolvedSquad] = $this->assertWritable($memberInput, $squadInput);

        $current = Interval::of(
            FactualPeriod::displayStart($assignment),
            FactualPeriod::displayEnd($assignment, $this->today())
        );
        $next = Interval::of($dateFrom, $dateTo);
        $sameSquad = $squad->getId() === $assignment->get('boardSquadId') ||
            (is_string($squad->get('teamId')) &&
                $squad->get('teamId') === $assignment->get('teamId'));
        $structuralChange = !$sameSquad ||
            $position !== $assignment->get('position');

        // Only a new team or position is validated against the current
        // position list. Ending or shortening a period keeps its stored
        // position even if that position was since removed from the team,
        // otherwise such a member could not be dragged to Reserve (U21).
        if ($structuralChange && !in_array($position, $resolvedSquad->positionList, true)) {
            throw new BadRequest('Not allowed position.');
        }

        if ($closingSameDay && !$structuralChange) {
            if (!Zone::mayChange($current, $next, false, $this->today(), $this->user->isAdmin())) {
                throw $this->forbidden('periodChange');
            }

            // CrmPlacement::forUser derives today's placement from the real
            // team_user relation and defaultTeamId, not from this row's own
            // dateTo — so ending a live CRM-state fact here must unrelate
            // that membership immediately (same single unrelate the board's
            // own drag-out uses in Board\Service::removeMember), rather than
            // leaving it for the scheduled ApplyDueAssignments job, which
            // would leave the member on the team until its next run
            // ("nothing happens or very long wait", T16/T20).
            if ($assignment->get('isCrmState') === true) {
                $crmTeamId = is_string($assignment->get('teamId')) ? $assignment->get('teamId') : null;
                $crmUserId = is_string($assignment->get('memberId')) ? $assignment->get('memberId') : null;

                if ($crmTeamId !== null && $crmUserId !== null) {
                    $crmUser = $this->entityManager->getRDBRepositoryByClass(User::class)->getById($crmUserId);
                    $crmTeam = $this->entityManager->getRDBRepositoryByClass(Team::class)->getById($crmTeamId);

                    if ($crmUser && $crmTeam) {
                        $relation = $this->entityManager->getRelation($crmTeam, 'users');

                        if ($relation->isRelated($crmUser)) {
                            // Triggers Hooks\Team\ResetFuturePlans, which records
                            // the resulting Reserve/next-team fact — the one
                            // recorder call this change relies on, not a second one here.
                            $relation->unrelate($crmUser);
                        }

                        if ($crmUser->get('defaultTeamId') === $crmTeamId) {
                            $crmUser->set(['defaultTeamId' => null, 'defaultTeamName' => null]);
                            $this->entityManager->saveEntity($crmUser);
                        }
                    }
                } elseif ($crmTeamId === null && $crmUserId !== null &&
                    is_string($assignment->get('boardSquadId')) && $assignment->get('boardSquadId') !== ''
                ) {
                    // A CRM user's board-only period holds no CRM team membership
                    // to unrelate (no Hooks\Team\ResetFuturePlans will fire), so
                    // the resulting Reserve/next-team fact must be recorded here
                    // directly — the same case endLinkedMembershipNow() handles
                    // for a period ending on schedule (section 1, T20). This row
                    // is deleted first so the recorder's own lookup of the
                    // member's periods no longer sees it as covering today.
                    $this->editor->delete($assignment);
                    $this->recorder->recordForUser($crmUserId, $this->today());

                    return $this->refreshed(
                        property_exists($data, 'viewDate') && is_string($data->viewDate) ? $data->viewDate : $dateTo
                    );
                }
            }

            $viewDate = property_exists($data, 'viewDate') && is_string($data->viewDate)
                ? $data->viewDate
                : $dateTo;
            // This exact row never covered any date (zero-length); the CRM
            // unrelate above (or plain deletion, for a board-only squad) is
            // the actual removal.
            $this->editor->delete($assignment);

            return $this->refreshed($viewDate);
        }

        // A repeated identical transition request whose successor was already
        // created (and, when due, applied at once) is a no-op, not a new
        // change of the now closed period.
        if ($structuralChange && $status === Status::CONFIRMED && $this->entityManager
            ->getRDBRepository(AssignmentQuery::ENTITY_TYPE)->where([
                'supersedesId' => $assignment->getId(),
                'boardSquadId' => $squad->getId(),
                'position' => $position,
                'dateFrom' => max($dateFrom, $this->today()),
                'dateTo' => $dateTo,
                'status' => Status::CONFIRMED,
            ])->findOne()) {
            return $this->refreshed($data->viewDate ?? max($dateFrom, $this->today()));
        }

        if (!Zone::mayChange(
            $current,
            $next,
            $structuralChange,
            $this->today(),
            $this->user->isAdmin()
        )) {
            throw $this->forbidden('periodChange');
        }

        // An actual current period is evidence of earlier CRM state. A new
        // team or position is a transition, never an edit of that evidence.
        if ($assignment->get('status') === Status::CONFIRMED &&
            $current->zoneAt($this->today()) === Interval::ZONE_LIVE) {
            if ($status !== Status::CONFIRMED) {
                throw $this->forbidden('actualToDraft');
            }
            if ($structuralChange) {
                $effectiveStart = max($dateFrom, $this->today());
                if ($dateTo !== null && $dateTo <= $effectiveStart) {
                    throw new BadRequest('End date must be after start date.');
                }
                $existing = $this->entityManager->getRDBRepository(AssignmentQuery::ENTITY_TYPE)->where([
                    'supersedesId' => $assignment->getId(),
                    'boardSquadId' => $squad->getId(),
                    'position' => $position,
                    'dateFrom' => $effectiveStart,
                    'dateTo' => $dateTo,
                    'status' => Status::CONFIRMED,
                ])->findOne();
                if ($existing) {
                    return $this->refreshed($data->viewDate ?? $effectiveStart);
                }
                $successor = $this->editor->createShadow(
                    (string) $assignment->get('boardMemberId'),
                    $squad->getId(),
                    is_string($assignment->get('memberId')) ? $assignment->get('memberId') : null,
                    is_string($squad->get('teamId')) ? $squad->get('teamId') : null,
                    $position, $effectiveStart, $dateTo, $status, null, $resolvedSquad->positionList,
                );
                $this->applyIfDue($successor);
                return $this->refreshed($data->viewDate ?? $effectiveStart);
            }
            if ($dateFrom !== $assignment->get('dateFrom') && $dateFrom !== $current->getDateFrom()) {
                throw $this->forbidden('actualStart');
            }
            if ($dateTo !== null && $dateTo < $this->today()) {
                throw $this->forbidden('actualEndPast');
            }
            if ($dateTo !== $assignment->get('dateTo')) {
                $assignment->set('dateTo', $dateTo);
                $this->entityManager->saveEntity($assignment);
            }
            // Ending the actual period today (drag to Reserve): today's
            // placement is read from the real CRM membership, so apply the
            // due end now instead of waiting for ApplyDueAssignments (T16/T20).
            if ($dateTo === $this->today()) {
                $this->endLinkedMembershipNow($assignment->getId(), $this->today());
            }
            return $this->refreshed($data->viewDate ?? $this->today());
        }

        $this->editor->updateShadow(
            $assignment,
            $position,
            $squad->getId(),
            is_string($squad->get('teamId')) ? $squad->get('teamId') : null,
            $dateFrom,
            $dateTo,
            $status,
            $note,
            $resolvedSquad->positionList,
        );
        $this->applyIfDue($assignment);
        $this->draftReminder->notifyForAssignment($assignment, $this->today());

        return $this->refreshed($data->viewDate ?? $dateFrom);
    }

    /**
     * Same end step ApplyDueAssignments runs for an applied period whose
     * Until has come: unrelate the CRM team membership and record the fact.
     */
    private function endLinkedMembershipNow(string $assignmentId, string $today): void
    {
        $this->entityManager->getTransactionManager()->run(function () use ($assignmentId, $today): void {
            $assignment = $this->query->lockForUpdate($assignmentId);

            if (!$assignment || $assignment->get('endedAt') !== null ||
                $assignment->get('status') !== Status::CONFIRMED ||
                $assignment->get('appliedAt') === null ||
                $assignment->get('dateTo') !== $today) {
                return;
            }

            $memberId = $assignment->get('memberId');
            $teamId = $assignment->get('teamId');

            // A CRM user's board-only period holds no CRM membership: ending
            // it only records the resulting Reserve fact (section 1, T20).
            if (is_string($memberId) && $teamId === null && is_string($assignment->get('boardSquadId')) &&
                !$this->query->hasPendingReplacement($assignment, $today)) {
                $assignment->set(['endedAt' => date('Y-m-d H:i:s'), 'actualDateTo' => $today]);
                $this->entityManager->saveEntity($assignment);
                $this->recorder->recordForUser($memberId, $today);

                return;
            }

            if (!is_string($memberId) || !is_string($teamId) ||
                $this->query->hasPendingReplacement($assignment, $today)) {
                return;
            }

            $member = $this->entityManager->getRDBRepositoryByClass(User::class)
                ->forUpdate()->where(['id' => $memberId])->findOne();
            $team = $this->entityManager->getRDBRepositoryByClass(Team::class)->getById($teamId);

            if (!$member || !$team || !$member->isActive()) {
                return;
            }

            foreach ($this->query->findCoveringDate($today) as $current) {
                if ($current->getId() !== $assignmentId &&
                    $current->get('memberId') === $memberId && $current->get('teamId') === $teamId &&
                    $current->get('status') === Status::CONFIRMED && $current->get('appliedAt') !== null) {
                    return;
                }
            }

            $options = [FuturePlanResetter::SKIP_OPTION => true];
            $relation = $this->entityManager->getRelation($team, 'users');

            if ($relation->isRelated($member)) {
                $relation->unrelate($member, $options);
            }

            if ($member->get('defaultTeamId') === $teamId) {
                $member->set(['defaultTeamId' => null, 'defaultTeamName' => null]);
                $this->entityManager->saveEntity($member, $options);
            }

            $assignment->set(['endedAt' => date('Y-m-d H:i:s'), 'actualDateTo' => $today]);
            $this->entityManager->saveEntity($assignment);
            $this->recorder->recordForUser($memberId, $today);
        });
    }

    /** @throws Forbidden|NotFound */
    public function deleteAssignment(string $id, ?string $viewDate = null): stdClass
    {
        $assignment = $this->getAssignment($id);
        $this->assertWritable(
            (string) ($assignment->get('boardMemberId') ?: $assignment->get('memberId')),
            (string) ($assignment->get('boardSquadId') ?: $assignment->get('teamId')),
        );
        $interval = Interval::of((string) $assignment->get('dateFrom'), $assignment->get('dateTo'));

        if (!Zone::mayDelete(
            $interval,
            (string) $assignment->get('status'),
            $assignment->get('appliedAt'),
            $this->today(),
            $this->user->isAdmin()
        )) {
            throw $this->forbidden('draftOnly');
        }

        $dateFrom = (string) $assignment->get('dateFrom');
        $this->editor->delete($assignment);

        return $this->refreshed($viewDate ?? $dateFrom);
    }

    /**
     * Return the complete assignment history for one board member. This is
     * separate from the board's bounded timeline window so old assignments
     * and archived board-only squads remain available in the member card.
     *
     * @return stdClass[]
     */
    public function getMemberHistory(string $boardMemberId): array
    {
        $this->assertReadable();
        $member = $this->entityManager->getEntityById(TeamBoardMember::ENTITY_TYPE, $boardMemberId);

        if (!$member) {
            throw new NotFound('Board member not found.');
        }

        if (is_string($member->get('userId'))) {
            $this->entityProvider->getByClass(User::class, $member->get('userId'));
        }

        $visibleTeams = [];

        foreach ($this->findTeams() as $team) {
            $visibleTeams[$team->getId()] = true;
        }

        $resolvedMember = $this->registry->resolveMember($member);
        $history = [];

        foreach ($this->query->findForMember($boardMemberId, $resolvedMember->userId) as $assignment) {
            $squad = null;
            $boardSquadId = $assignment->get('boardSquadId');

            if (is_string($boardSquadId) && $boardSquadId !== '') {
                $squad = $this->entityManager->getEntityById(TeamBoardSquad::ENTITY_TYPE, $boardSquadId);
            }

            $teamId = $assignment->get('teamId');

            if (!$squad && is_string($teamId) && isset($visibleTeams[$teamId])) {
                $squad = $this->registry->squadForTeam($teamId);
            }

            if (!$squad && !$this->isUnassignedCrmFact($assignment)) {
                continue;
            }

            $resolvedSquad = $squad ? $this->registry->resolveSquad($squad) : null;

            if ($resolvedSquad?->isEspoTeam && !isset($visibleTeams[$resolvedSquad->teamId])) {
                continue;
            }

            $history[] = (object) [
                'id' => $assignment->getId(),
                'boardMemberId' => $boardMemberId,
                'boardSquadId' => $squad?->getId(),
                'teamId' => $resolvedSquad?->teamId,
                'teamName' => $resolvedSquad->name ?? '',
                'state' => $this->stateOf($assignment),
                'position' => $assignment->get('position'),
                'dateFrom' => FactualPeriod::displayStart($assignment),
                'dateTo' => FactualPeriod::displayEnd($assignment, $this->today()),
                'status' => $assignment->get('status'),
                'note' => $member->get('note') ?? '',
                'appliedAt' => $assignment->get('appliedAt'),
            ];
        }

        // T22 — only the last state of a day is shown: a period that ended
        // on the day it started was replaced that same day.
        $history = array_values(array_filter($history, static fn (stdClass $row): bool =>
            $row->dateTo === null || $row->dateTo > $row->dateFrom));

        $history = $this->collapseSameDayDuplicates($history);

        usort($history, fn (stdClass $a, stdClass $b): int =>
            [$b->dateFrom, $b->id] <=> [$a->dateFrom, $a->id]
        );

        return $history;
    }

    /**
     * T12 — History has no duplicates: records of the same person and team
     * for the same day are one row unless status or position differ. Two
     * assignment rows can legitimately exist for the same person/team/day
     * (e.g. a factual period recorded alongside its board-only counterpart),
     * so this collapses them for display rather than at the query layer.
     *
     * @param stdClass[] $history
     * @return stdClass[]
     */
    private function collapseSameDayDuplicates(array $history): array
    {
        $collapsed = [];

        foreach ($history as $row) {
            $day = substr((string) $row->dateFrom, 0, 10);
            $key = implode('|', [$row->teamId ?? '', $day, $row->status, $row->position ?? '']);

            if (isset($collapsed[$key])) {
                $existing = $collapsed[$key];

                // Keep the wider period: null dateTo (still ongoing) wins,
                // otherwise the later end date wins.
                if ($existing->dateTo !== null &&
                    ($row->dateTo === null || $row->dateTo > $existing->dateTo)
                ) {
                    $existing->dateTo = $row->dateTo;
                }

                // Keep the earliest id for determinism.
                if ($row->id < $existing->id) {
                    $existing->id = $row->id;
                }

                continue;
            }

            $collapsed[$key] = $row;
        }

        return array_values($collapsed);
    }

    private function refreshed(string $viewDate): stdClass
    {
        $referenceDate = new \DateTimeImmutable($viewDate);

        return $this->getTimeline(
            $viewDate,
            $referenceDate->sub(new \DateInterval('P6M'))->format('Y-m-d'),
            $referenceDate->add(new \DateInterval('P6M'))->format('Y-m-d'),
        );
    }

    private function today(): string
    {
        return $this->dateTime->getToday()->toString();
    }

    private function canManage(): bool
    {
        return $this->user->isAdmin() ||
            ($this->acl->checkScope(self::SCOPE, Table::ACTION_READ) &&
                $this->acl->checkScope(self::MANAGE_SCOPE, Table::ACTION_EDIT));
    }

    /**
     * @return array{Entity, Entity, stdClass}
     * @throws BadRequest|Forbidden
     */
    private function assertWritable(string $memberInput, string $squadInput): array
    {
        if (!$this->canManage()) {
            throw $this->forbidden('noEditAccess');
        }

        $member = $this->registry->memberFromInput($memberInput);
        $squad = $this->registry->squadFromInput($squadInput);
        $resolvedMember = $this->registry->resolveMember($member);
        $resolvedSquad = $this->registry->resolveSquad($squad);

        if ($member->get('isArchived') || $squad->get('isArchived')) {
            throw new BadRequest('Archived board records cannot receive new assignments.');
        }

        if ($resolvedMember->isEspoUser) {
            $linkedMember = $this->entityProvider->getByClass(User::class, $resolvedMember->userId);

            // Planning never edits the system User profile directly. Native
            // read access defines whether this person may be targeted.
        }

        if ($resolvedSquad->isEspoTeam) {
            $linkedTeam = $this->entityProvider->getByClass(Team::class, $resolvedSquad->teamId);

            // The scheduled transition later uses CRM's own Team relation;
            // planning itself requires visibility, not profile edit rights.
        }

        return [$member, $squad, $resolvedSquad];
    }

    /** @throws Forbidden */
    private function assertReadable(): void
    {
        if (!$this->user->isAdmin() &&
            !$this->acl->checkScope(self::SCOPE, Table::ACTION_READ)) {
            throw $this->forbidden('noAccess');
        }
    }

    /** @throws NotFound */
    private function getAssignment(string $id): Entity
    {
        $assignment = $this->entityManager->getEntityById(AssignmentQuery::ENTITY_TYPE, $id);

        if (!$assignment) {
            throw new NotFound('Assignment not found.');
        }

        return $assignment;
    }

    /**
     * @return array<int, Entity>
     */
    private function findSquads(string $date): array
    {
        $allowedTeamMap = [];

        foreach ($this->findTeams() as $team) {
            $allowedTeamMap[$team->getId()] = true;
            $this->registry->squadForTeam($team->getId());
        }

        $list = [];
        $collection = $this->entityManager->getRDBRepository(TeamBoardSquad::ENTITY_TYPE)->find();

        foreach ($collection as $squad) {
            $teamId = $squad->get('teamId');

            if (is_string($teamId) && $teamId !== '') {
                if (!isset($allowedTeamMap[$teamId])) {
                    continue;
                }
            }
            elseif (!$this->squadVisibleAt($squad, $date)) {
                continue;
            }

            $list[] = $squad;
        }

        usort($list, fn (Entity $a, Entity $b): int =>
            strcasecmp($this->registry->resolveSquad($a)->name, $this->registry->resolveSquad($b)->name)
        );

        return $list;
    }

    private function squadVisibleAt(Entity $squad, string $date): bool
    {
        if ($squad->get('teamId')) {
            return true;
        }

        $repository = $this->entityManager->getRDBRepository(TeamBoardSquadVisibility::ENTITY_TYPE);
        $event = $repository->where([
            'boardSquadId' => $squad->getId(),
            'effectiveDate<=' => $date,
        ])->order('effectiveDate', 'DESC')->order('createdAt', 'DESC')->findOne();

        if ($event) {
            return $event->get('state') === TeamBoardSquadVisibility::ACTIVE;
        }

        // A squad with explicit history was active before its first event.
        // Legacy rows without history retain their old flag until migration.
        if ($repository->where(['boardSquadId' => $squad->getId()])->count() > 0) {
            return true;
        }

        return !(bool) $squad->get('isArchived');
    }

    /** @return Team[] */
    private function findTeams(): array
    {
        try {
            $query = $this->selectBuilderFactory->create()->from(Team::ENTITY_TYPE)
                ->withStrictAccessControl()->buildQueryBuilder()->order('name')->build();
        }
        catch (Forbidden) {
            return [];
        }

        $collection = $this->entityManager->getRDBRepositoryByClass(Team::class)->clone($query)->find();
        $teams = [];

        foreach ($collection as $team) {
            $teams[] = $team;
        }

        return $teams;
    }

    /** @return array<string, User> */
    private function findVisibleUsers(): array
    {
        try {
            $query = $this->selectBuilderFactory->create()->from(User::ENTITY_TYPE)
                ->withStrictAccessControl()->buildQueryBuilder()->where([
                    'type' => [User::TYPE_REGULAR, User::TYPE_ADMIN],
                ])->build();
        }
        catch (Forbidden) {
            return [];
        }

        $collection = $this->entityManager->getRDBRepositoryByClass(User::class)->clone($query)->find();
        $map = [];

        foreach ($collection as $user) {
            $map[$user->getId()] = $user;
        }

        return $map;
    }

    /**
     * @param string[] $assignedBoardMemberIds
     * @param array<string, User> $allowedUsers
     * @param string[] $reserveUserIds
     * @param array<string, string> $draftDueDates
     * @return stdClass[]
     */
    private function findReserve(array $assignedBoardMemberIds, array $allowedUsers, array $reserveUserIds, string $date, array $draftDueDates): array
    {
        $assigned = array_fill_keys($assignedBoardMemberIds, true);
        $list = [];
        $collection = $this->entityManager
            ->getRDBRepository(TeamBoardMember::ENTITY_TYPE)
            ->where(['isArchived' => false])
            ->find();

        foreach ($collection as $member) {
            if (isset($assigned[$member->getId()])) {
                continue;
            }

            $userId = $member->get('userId');
            if (is_string($userId) &&
                (!isset($allowedUsers[$userId]) || !in_array($userId, $reserveUserIds, true))) {
                continue;
            }
            if ($userId === null && is_string($member->get('createdAt')) &&
                substr($member->get('createdAt'), 0, 10) > $date) {
                continue;
            }
            $resolved = $this->registry->resolveMember($member);

            $list[] = (object) [
                'id' => $resolved->userId ?? $member->getId(),
                'boardMemberId' => $member->getId(),
                'userId' => $resolved->userId,
                'isEspoUser' => $resolved->isEspoUser,
                'name' => $resolved->name,
                'userName' => $resolved->userId !== null
                    ? ($allowedUsers[$resolved->userId]->getUserName() ?? null)
                    : null,
                'systemAvatarId' => $resolved->systemAvatarId,
                'boardPhotoId' => $resolved->boardPhotoId,
                'avatarId' => $resolved->avatarId,
                'avatarColor' => $resolved->avatarColor,
                'firstName' => $resolved->firstName,
                'lastName' => $resolved->lastName,
                'boardNote' => $member->get('note') ?? '',
                'warnings' => isset($draftDueDates[$member->getId()]) ? ['draftDue'] : [],
                'draftDueDate' => $draftDueDates[$member->getId()] ?? null,
            ];
        }

        usort($list, fn (stdClass $a, stdClass $b): int => strcasecmp($a->name, $b->name));

        return $list;
    }

    /**
     * U06: a person is shown once. When several Drafts of one person are in
     * effect on the viewed date, only the one that started last (then the
     * newest id) is kept; older overlapping Drafts are not shown as copies.
     *
     * @param Entity[] $allCovering
     * @return Entity[]
     */
    private function latestDraftPerMember(array $allCovering): array
    {
        $latest = [];
        foreach ($allCovering as $assignment) {
            if ($assignment->get('status') !== Status::DRAFT) {
                continue;
            }
            $memberId = (string) $this->memberForAssignment($assignment)?->getId();
            $key = [(string) $assignment->get('dateFrom'), $assignment->getId()];
            if (!isset($latest[$memberId]) || $key > $latest[$memberId]) {
                $latest[$memberId] = $key;
            }
        }

        return array_values(array_filter(
            $allCovering,
            fn (Entity $assignment): bool => $assignment->get('status') !== Status::DRAFT ||
                ($latest[(string) $this->memberForAssignment($assignment)?->getId()][1] ?? null) ===
                    $assignment->getId()
        ));
    }

    /**
     * U06: where each person with a Draft in effect on the viewed date is
     * placed without that Draft. `squad` is null for Reserve; `hidden` marks
     * a previous place the viewer cannot see (its name is not disclosed).
     *
     * @param Entity[] $allCovering
     * @param Entity[] $squads
     * @return array<string, array{squad: ?Entity, hidden: bool}>
     */
    private function draftOrigins(array $allCovering, array $squads): array
    {
        $origins = [];
        foreach ($allCovering as $assignment) {
            if ($assignment->get('status') === Status::DRAFT) {
                $member = $this->memberForAssignment($assignment);
                if ($member) {
                    $origins[$member->getId()] = ['squad' => null, 'hidden' => false];
                }
            }
        }
        foreach ($allCovering as $assignment) {
            if ($assignment->get('status') === Status::DRAFT) {
                continue;
            }
            $memberId = $this->memberForAssignment($assignment)?->getId();
            if ($memberId === null || !isset($origins[$memberId]) || $origins[$memberId]['squad']) {
                continue;
            }
            $origins[$memberId]['hidden'] = true;
            foreach ($squads as $squad) {
                if ($this->assignmentBelongsToSquad($assignment, $squad)) {
                    $origins[$memberId] = ['squad' => $squad, 'hidden' => false];
                    break;
                }
            }
        }

        return $origins;
    }

    /** @param ?array{squad: ?Entity, hidden: bool} $origin */
    private function draftOriginFor(?array $origin, Entity $destination): ?stdClass
    {
        if ($origin === null || $origin['hidden'] ||
            ($origin['squad'] && $origin['squad']->getId() === $destination->getId())) {
            return null;
        }
        if (!$origin['squad']) {
            return (object) ['isReserve' => true, 'boardSquadId' => null, 'name' => null];
        }

        return (object) [
            'isReserve' => false,
            'boardSquadId' => $origin['squad']->getId(),
            'name' => $this->registry->resolveSquad($origin['squad'])->name,
        ];
    }

    private function memberForAssignment(Entity $assignment): ?Entity
    {
        $boardMemberId = $assignment->get('boardMemberId');

        if (is_string($boardMemberId) && $boardMemberId !== '') {
            $member = $this->entityManager->getEntityById(TeamBoardMember::ENTITY_TYPE, $boardMemberId);
            // Do not expose a contradictory legacy id belonging to another user.
            if ($member && $assignment->get('memberId') !== null &&
                $assignment->get('memberId') !== $member->get('userId')) {
                return null;
            }
            return $member;
        }

        $memberId = $assignment->get('memberId');

        return is_string($memberId) && $memberId !== '' &&
            $this->entityManager->getRDBRepositoryByClass(User::class)->getById($memberId)
            ? $this->registry->memberForUser($memberId)
            : null;
    }

    private function assignmentBelongsToSquad(Entity $assignment, Entity $squad): bool
    {
        if (is_string($assignment->get('boardSquadId')) && $assignment->get('boardSquadId') !== '') {
            return $assignment->get('boardSquadId') === $squad->getId();
        }

        $teamId = $squad->get('teamId');

        return is_string($teamId) && $teamId !== '' && $assignment->get('teamId') === $teamId;
    }

    /**
     * @param array<int, Entity> $assignments
     * @param array<int, string> $squadIds
     * @param array<int, string> $teamIds
     * @return array<int, Entity>
     */
    private function filterForSquads(array $assignments, array $squadIds, array $teamIds): array
    {
        return array_values(array_filter(
            $assignments,
            fn (Entity $assignment): bool => is_string($assignment->get('boardSquadId')) && $assignment->get('boardSquadId') !== ''
                ? in_array($assignment->get('boardSquadId'), $squadIds, true)
                : in_array($assignment->get('teamId'), $teamIds, true)
        ));
    }

    private function isUnassignedCrmFact(Entity $assignment): bool
    {
        return (bool) $assignment->get('isCrmState') && $assignment->get('appliedAt') !== null &&
            $assignment->get('teamId') === null && $assignment->get('boardSquadId') === null;
    }

    private function stateOf(Entity $assignment): string
    {
        if ($assignment->get('isCrmState') && !$assignment->get('crmIsActive')) {
            return 'inactive';
        }
        return $this->isUnassignedCrmFact($assignment) ? 'reserve' : 'team';
    }

    private function toPlain(Entity $entity): stdClass
    {
        return (object) [
            'id' => $entity->getId(),
            'boardMemberId' => $entity->get('boardMemberId'),
            'boardSquadId' => $entity->get('boardSquadId'),
            'memberId' => $entity->get('memberId'),
            'teamId' => $entity->get('teamId'),
            'position' => $entity->get('position'),
            'dateFrom' => FactualPeriod::displayStart($entity),
            'dateTo' => FactualPeriod::displayEnd($entity, $this->today()),
            'status' => $entity->get('status'),
            'state' => $this->stateOf($entity),
            'appliedAt' => $entity->get('appliedAt'),
            'note' => $this->memberForAssignment($entity)?->get('note') ?? '',
        ];
    }

    /** @throws BadRequest */
    private function assertStatusAndInterval(
        string $status,
        string $dateFrom,
        ?string $dateTo,
        bool $allowZeroLength = false
    ): void {
        if (!in_array($status, Status::LIST, true)) {
            throw new BadRequest('Bad status.');
        }

        if (!$this->isDate($dateFrom) || ($dateTo !== null && !$this->isDate($dateTo))) {
            throw new BadRequest('Dates must use YYYY-MM-DD.');
        }

        if ($allowZeroLength && $dateTo === $dateFrom) {
            return;
        }

        if (!Interval::of($dateFrom, $dateTo)->isValid()) {
            throw new BadRequest('End date must be after the start date.');
        }
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return (bool) $date && $date->format('Y-m-d') === $value;
    }

    /** @throws BadRequest */
    private function requireString(stdClass $data, string $key): string
    {
        $value = $data->$key ?? null;

        if (!is_string($value) || $value === '') {
            throw new BadRequest("Bad $key.");
        }

        return $value;
    }

    /** @throws BadRequest */
    private function optionalString(stdClass $data, string $key): ?string
    {
        $value = $data->$key ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new BadRequest("Bad $key.");
        }

        return $value;
    }
}
