<?php

namespace Espo\Modules\TeamBoard\Tools\Shadow;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\EntityProvider;
use Espo\Core\Utils\DateTime;
use Espo\Core\Utils\Language;
use Espo\Modules\TeamBoard\Tools\Support\LocalizedDenial;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Entities\TeamBoardMember;
use Espo\Modules\TeamBoard\Entities\TeamBoardSquad;
use Espo\Modules\TeamBoard\Entities\TeamBoardSquadVisibility;
use Espo\Modules\TeamBoard\Tools\Board\Service as BoardService;
use Espo\Modules\TeamBoard\Tools\Timeline\AssignmentEditor;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

class ManagementService
{
    use LocalizedDenial;

    private const MANAGE_SCOPE = 'TeamBoardAssignment';

    public function __construct(
        private Acl $acl,
        private User $user,
        private EntityManager $entityManager,
        private BoardService $boardService,
        private DateTime $dateTime,
        private AssignmentEditor $assignmentEditor,
        private EntityProvider $entityProvider,
        private Language $language,
    ) {}

    public function createMember(stdClass $data): stdClass
    {
        $this->assertCanEdit();
        $this->assertOnlyKeys($data, ['firstName', 'lastName', 'note', 'name']);
        [$firstName, $lastName, $name] = $this->memberName($data);

        $member = $this->entityManager->createEntity(TeamBoardMember::ENTITY_TYPE, [
            'userId' => null,
            'name' => $name,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'note' => property_exists($data, 'note') ? $this->note($data->note) : '',
            'isArchived' => false,
        ]);

        return $this->withId($this->boardService->getData(), 'boardMemberId', $member->getId());
    }

    public function updateMember(string $id, stdClass $data): stdClass
    {
        $this->assertCanEdit();
        $this->assertOnlyKeys($data, ['firstName', 'lastName', 'note', 'name']);
        $member = $this->member($id);

        if ($member->get('userId') &&
            (property_exists($data, 'firstName') || property_exists($data, 'lastName') || property_exists($data, 'name'))) {
            throw new BadRequest('Linked EspoCRM identity is read-only in Team Board.');
        }

        if ($member->get('isArchived')) {
            throw new BadRequest('Archived board member cannot be edited.');
        }

        if (!$member->get('userId') &&
            (property_exists($data, 'firstName') || property_exists($data, 'lastName') || property_exists($data, 'name'))) {
            [$firstName, $lastName, $name] = $this->memberName($data, $member);
            $member->set([
                'firstName' => $firstName,
                'lastName' => $lastName,
                'name' => $name,
            ]);
        }
        if (property_exists($data, 'note')) {
            $member->set('note', $this->note($data->note));
        }
        $this->entityManager->saveEntity($member);

        return $this->withId($this->boardService->getData(), 'boardMemberId', $member->getId());
    }

    public function archiveMember(string $id): stdClass
    {
        $this->assertCanEdit();
        $member = $this->member($id);

        if ($member->get('userId')) {
            throw new BadRequest('Linked EspoCRM user cannot be archived from Team Board.');
        }

        $archiveDate = $this->dateTime->getToday()->toString();

        // P12: the archive flag, the end of current periods and the removal
        // of future plans succeed or fail together.
        $this->entityManager->getTransactionManager()->run(function () use ($member, $archiveDate): void {
            $this->endPeriodsOnArchive($member->getId(), $archiveDate);

            if (!$member->get('isArchived')) {
                $member->set('isArchived', true);
                $this->entityManager->saveEntity($member);
            }
        });

        return $this->boardService->getData();
    }

    /**
     * Past periods stay as history. A period that has not started before the
     * archive date (Draft or confirmed) is removed; one still running on that
     * date is ended on it (`dateTo` is exclusive).
     */
    private function endPeriodsOnArchive(string $memberId, string $archiveDate): void
    {
        $repository = $this->entityManager->getRDBRepository(AssignmentEditor::ENTITY_TYPE);

        // Latest first, so each removed plan restores the boundary it closed
        // before the earlier period it closed is itself handled.
        $future = $repository->where([
            'boardMemberId' => $memberId,
            'dateFrom>=' => $archiveDate,
        ])->order('dateFrom', 'DESC')->find();

        foreach ($future as $assignment) {
            $this->assignmentEditor->delete($assignment);
        }

        $running = $repository->where([
            'boardMemberId' => $memberId,
            'dateFrom<' => $archiveDate,
            'OR' => [
                ['dateTo' => null],
                ['dateTo>' => $archiveDate],
            ],
        ])->find();

        foreach ($running as $assignment) {
            $this->assignmentEditor->closeAt($assignment, $archiveDate, null);
        }
    }

    public function createSquad(stdClass $data): stdClass
    {
        $this->assertCanEdit();
        $this->assertOnlyKeys($data, ['name', 'positionList', 'colourKey']);
        $positions = property_exists($data, 'positionList')
            ? $this->positions($data->positionList)
            : TeamBoardSquad::DEFAULT_POSITION_LIST;
        $colourKey = property_exists($data, 'colourKey')
            ? $this->colourKey($data->colourKey)
            : TeamBoardSquad::COLOUR_SLATE;

        $this->entityManager->createEntity(TeamBoardSquad::ENTITY_TYPE, [
            'teamId' => null,
            'name' => $this->name($data->name ?? null),
            'positionList' => $positions,
            'colourKey' => $colourKey,
            'isArchived' => false,
        ]);

        return $this->boardService->getData();
    }

    public function updateSquad(string $id, stdClass $data): stdClass
    {
        $this->assertCanEdit();
        $this->assertOnlyKeys($data, ['name', 'positionList', 'colourKey']);
        $squad = $this->squad($id);

        if ($squad->get('isArchived')) {
            throw new BadRequest('Archived board team cannot be edited.');
        }

        if ($squad->get('teamId') &&
            (property_exists($data, 'name') || property_exists($data, 'positionList'))) {
            throw new BadRequest('Linked EspoCRM team name and positions are read-only.');
        }

        if (property_exists($data, 'name')) {
            $squad->set('name', $this->name($data->name));
        }

        if (property_exists($data, 'positionList')) {
            $squad->set('positionList', $this->positions($data->positionList));
        }

        if (property_exists($data, 'colourKey')) {
            $squad->set('colourKey', $this->colourKey($data->colourKey));
        }

        $this->entityManager->saveEntity($squad);

        return $this->boardService->getData();
    }

    public function archiveSquad(string $id, ?string $fromDate = null): stdClass
    {
        $this->assertCanEdit();
        $squad = $this->squad($id);

        if ($squad->get('teamId')) {
            throw new BadRequest('Linked EspoCRM team cannot be archived from Team Board.');
        }

        $fromDate = $this->archiveDate($fromDate);

        $this->entityManager->getTransactionManager()->run(function () use ($squad, $fromDate): void {
            $this->assertNoCurrentOrFutureAssignments($squad, $fromDate);
            // C07: the archive applies from the open month's start. A later
            // transition (e.g. a restore earlier this month) would otherwise
            // outrank it and leave the team on the board.
            $this->clearFutureVisibility($squad, $fromDate);
            $this->setVisibility($squad, $fromDate, TeamBoardSquadVisibility::ARCHIVED);
            $this->syncLegacyArchiveFlag($squad);
        });

        return $this->boardService->getData();
    }

    public function restoreSquad(string $id, ?string $fromDate = null): stdClass
    {
        $this->assertCanEdit();
        $squad = $this->squad($id);
        if ($squad->get('teamId')) throw new BadRequest('Linked EspoCRM team cannot be restored from Team Board.');

        // Restoration always starts now. The open board month is deliberately
        // irrelevant and any scheduled future visibility action is cancelled.
        $fromDate = $this->dateTime->getToday()->toString();

        $this->entityManager->getTransactionManager()->run(function () use ($squad, $fromDate): void {
            $this->clearFutureVisibility($squad, $fromDate);
            $this->setVisibility($squad, $fromDate, TeamBoardSquadVisibility::ACTIVE);
            $this->syncLegacyArchiveFlag($squad);
        });

        return $this->boardService->getData();
    }

    /** @return array<int, array{id: string, name: string, colourKey: string}> */
    public function archivedSquads(?string $viewDate = null): array
    {
        $this->assertCanEdit();
        // C08: a team restored after the open month's start is active now and
        // is not offered for restore again; the state is read at the later of
        // the month start and today.
        $asOfDate = max($this->archiveDate($viewDate), $this->dateTime->getToday()->toString());
        $items = [];

        foreach ($this->entityManager->getRDBRepository(TeamBoardSquad::ENTITY_TYPE)
            ->find() as $squad) {
            if (!$squad->get('teamId') && $this->isArchivedAsOf($squad, $asOfDate)) {
                $items[] = ['id' => $squad->getId(), 'name' => (string) $squad->get('name'),
                    'colourKey' => (string) $squad->get('colourKey')];
            }
        }

        return $items;
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

    private function squad(string $id): Entity
    {
        return $this->entityManager->getEntityById(TeamBoardSquad::ENTITY_TYPE, $id) ??
            throw new NotFound('Board team not found.');
    }

    private function name(mixed $value): string
    {
        if (!is_string($value)) {
            throw new BadRequest('Bad name.');
        }

        $name = trim($value);

        if ($name === '' || mb_strlen($name) > 150) {
            throw new BadRequest('Name must contain 1 to 150 characters.');
        }

        return $name;
    }

    /** @return array{string, string, string} */
    private function memberName(stdClass $data, ?Entity $current = null): array
    {
        $hasParts = property_exists($data, 'firstName') || property_exists($data, 'lastName');

        if (!$hasParts && property_exists($data, 'name')) {
            $name = $this->name($data->name);
            $parts = preg_split('/\s+/', $name, 2) ?: [];

            return $this->checkedName($parts[0], $parts[1] ?? '', $name);
        }

        // A partial update carries only the edited part of the name pair. The
        // other part keeps its stored value instead of being rejected as
        // missing or silently cleared.
        $firstName = property_exists($data, 'firstName')
            ? $this->name($data->firstName)
            : $this->name($current?->get('firstName'));

        $lastName = property_exists($data, 'lastName')
            ? $this->lastName($data->lastName)
            : $this->lastName($current?->get('lastName'));

        return $this->checkedName($firstName, $lastName, MemberNameLimits::fullName($firstName, $lastName));
    }

    /**
     * The columns are narrower than one name part (100) and than the joined
     * name (150): refuse with 400 here instead of failing in the database.
     *
     * @return array{string, string, string}
     */
    private function checkedName(string $firstName, string $lastName, string $fullName): array
    {
        $violation = MemberNameLimits::violation($firstName, $lastName);

        if ($violation !== null || mb_strlen($fullName) > MemberNameLimits::FULL) {
            throw $this->badRequest('nameLength');
        }

        return [$firstName, $lastName, $fullName];
    }

    private function lastName(mixed $value): string
    {
        $lastName = is_string($value) ? trim($value) : '';

        if (mb_strlen($lastName) > MemberNameLimits::PART) {
            throw $this->badRequest('nameLength');
        }

        return $lastName;
    }

    private function note(mixed $value): string
    {
        // An emptied native text field sends null. Clearing the note is a
        // supported edit, so it is stored as an empty note.
        if ($value === null) {
            return '';
        }

        if (!is_string($value)) {
            throw new BadRequest('Bad note.');
        }

        if (mb_strlen($value) > 4000) {
            throw new BadRequest('Note must contain at most 4000 characters.');
        }

        return trim($value);
    }

    private function archiveDate(?string $value): string
    {
        $value ??= $this->dateTime->getToday()->toString();

        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) {
            throw new BadRequest('Bad viewDate.');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new BadRequest('Bad viewDate.');
        }

        return $date->format('Y-m-01');
    }

    private function setVisibility(Entity $squad, string $effectiveDate, string $state): void
    {
        $repository = $this->entityManager->getRDBRepository(TeamBoardSquadVisibility::ENTITY_TYPE);
        $existing = $repository->where([
            'boardSquadId' => $squad->getId(),
            'effectiveDate' => $effectiveDate,
        ])->order('createdAt', 'DESC')->findOne();

        if ($existing) {
            if ($existing->get('state') !== $state) {
                $existing->set('state', $state);
                $this->entityManager->saveEntity($existing);
            }

            return;
        }

        // The unique squad/date/state index also counts soft-deleted rows
        // (C08): purge any left by earlier cycles before inserting.
        $this->entityManager->getQueryExecutor()->execute(
            $this->entityManager->getQueryBuilder()->delete()
                ->from(TeamBoardSquadVisibility::ENTITY_TYPE)
                ->where([
                    'boardSquadId' => $squad->getId(),
                    'effectiveDate' => $effectiveDate,
                    'deleted' => true,
                ])
                ->build()
        );

        $this->entityManager->createEntity(TeamBoardSquadVisibility::ENTITY_TYPE, [
            'boardSquadId' => $squad->getId(),
            'effectiveDate' => $effectiveDate,
            'state' => $state,
        ]);
    }

    private function clearFutureVisibility(Entity $squad, string $date): void
    {
        $events = $this->entityManager
            ->getRDBRepository(TeamBoardSquadVisibility::ENTITY_TYPE)
            ->where([
                'boardSquadId' => $squad->getId(),
                'effectiveDate>' => $date,
            ])
            ->find();

        // Hard delete: a cancelled transition is not history, and a
        // soft-deleted row would still occupy the unique index (C08).
        foreach ($events as $event) {
            $this->entityManager->getRDBRepository(TeamBoardSquadVisibility::ENTITY_TYPE)
                ->deleteFromDb($event->getId());
        }
    }

    private function assertNoCurrentOrFutureAssignments(Entity $squad, string $fromDate): void
    {
        $repository = $this->entityManager->getRDBRepository('TeamBoardAssignment');
        $count = $repository->where([
            'boardSquadId' => $squad->getId(),
            'OR' => [
                ['dateTo' => null],
                ['dateTo>' => $fromDate],
            ],
        ])->count();

        if ($count > 0) {
            throw new BadRequest(
                'A team with current or future assignments cannot be archived.'
            );
        }
    }

    private function isArchivedAsOf(Entity $squad, string $date): bool
    {
        $event = $this->entityManager
            ->getRDBRepository(TeamBoardSquadVisibility::ENTITY_TYPE)
            ->where([
                'boardSquadId' => $squad->getId(),
                'effectiveDate<=' => $date,
            ])
            ->order('effectiveDate', 'DESC')
            ->order('createdAt', 'DESC')
            ->findOne();

        return $event
            ? $event->get('state') === TeamBoardSquadVisibility::ARCHIVED
            : (bool) $squad->get('isArchived');
    }

    private function syncLegacyArchiveFlag(Entity $squad): void
    {
        $currentDate = $this->dateTime->getToday()->toString();
        $event = $this->entityManager
            ->getRDBRepository(TeamBoardSquadVisibility::ENTITY_TYPE)
            ->where([
                'boardSquadId' => $squad->getId(),
                'effectiveDate<=' => $currentDate,
            ])
            ->order('effectiveDate', 'DESC')
            ->order('createdAt', 'DESC')
            ->findOne();

        if ($event) {
            $squad->set('isArchived', $event->get('state') === TeamBoardSquadVisibility::ARCHIVED);
            $this->entityManager->saveEntity($squad);
        }
    }

    private function withId(stdClass $data, string $key, string $id): stdClass
    {
        $data->{$key} = $id;

        return $data;
    }

    /** @return string[] */
    private function positions(mixed $value): array
    {
        if (!is_array($value)) {
            throw new BadRequest('Bad positionList.');
        }

        $positions = [];

        foreach ($value as $position) {
            if (!is_string($position)) {
                throw new BadRequest('Bad positionList.');
            }

            $position = trim($position);

            if ($position === '' || mb_strlen($position) > 100) {
                throw new BadRequest('Each position must contain 1 to 100 characters.');
            }

            $positions[$position] = true;
        }

        if ($positions === [] || count($positions) > 20) {
            throw new BadRequest('Position list must contain 1 to 20 unique values.');
        }

        return array_keys($positions);
    }

    private function colourKey(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, TeamBoardSquad::COLOUR_LIST, true)) {
            throw new BadRequest('Bad colourKey.');
        }

        return $value;
    }
}
