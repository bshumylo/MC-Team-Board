<?php

namespace Espo\Modules\TeamBoard\Tools\Timeline;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Name\Attribute;

/**
 * Every read of the assignment table.
 *
 * The core predicate — `dateFrom <= :date AND (dateTo IS NULL OR dateTo >
 * :date)` — is identical for past, present and future, so history needs no
 * separate code path. It is written once, here.
 *
 * Access control is not applied at this level. Callers hold the ACL gate; the
 * scheduled jobs deliberately run without one.
 */
class AssignmentQuery
{
    public const ENTITY_TYPE = 'TeamBoardAssignment';

    public function __construct(private EntityManager $entityManager) {}

    /** Re-read persisted state while the caller holds a transaction. */
    public function lockForUpdate(string $id): ?Entity
    {
        return $this->entityManager->getRDBRepository(self::ENTITY_TYPE)
            ->forUpdate()->where(['id' => $id])->findOne();
    }

    /** @return Entity[] */
    public function findActualCoveringDate(string $date): array
    {
        return $this->findActualInRange($date, (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d'));
    }

    /** @return Entity[] */
    public function findActualInRange(string $from, string $until): array
    {
        return $this->find([
            'memberId!=' => null,
            'status' => Status::CONFIRMED,
            'appliedAt!=' => null,
            'AND' => [
                ['OR' => [
                    ['actualDateFrom<' => $until],
                    ['actualDateFrom' => null, 'dateFrom<' => $until],
                ]],
                ['OR' => [
                    ['actualDateTo>' => $from],
                    ['actualDateTo' => null, 'OR' => [
                        ['actualDateFrom!=' => null],
                        ['isCrmState' => true, 'endedAt' => null],
                        ['dateTo' => null],
                        ['dateTo>' => $from],
                    ]],
                ]],
            ],
        ], 'dateFrom');
    }

    /**
     * Assignments whose period covers the date, of any status.
     *
     * @param ?string[] $boardSquadIds
     * @param ?string[] $teamIds
     * @return Entity[]
     */
    public function findCoveringDate(
        string $date,
        ?array $boardSquadIds = null,
        ?array $teamIds = null
    ): array
    {
        $where = [
            'dateFrom<=' => $date,
            'OR' => [
                ['dateTo' => null],
                ['dateTo>' => $date],
            ],
        ];

        if ($boardSquadIds !== null) {
            $where = [
                'dateFrom<=' => $date,
                'AND' => [
                    ['OR' => [
                        ['dateTo' => null],
                        ['dateTo>' => $date],
                    ]],
                    ['OR' => [
                        ['boardSquadId' => $boardSquadIds],
                        ['teamId' => $teamIds ?? $boardSquadIds],
                    ]],
                ],
            ];
        }

        return $this->find($where, 'dateFrom');
    }

    /**
     * Assignments whose period intersects `[dateFrom, dateTo)`.
     *
     * @param ?string[] $boardSquadIds
     * @param ?string[] $teamIds
     * @return Entity[]
     */
    public function findInRange(
        string $dateFrom,
        string $dateTo,
        ?array $boardSquadIds = null,
        ?array $teamIds = null
    ): array
    {
        $where = [
            'dateFrom<' => $dateTo,
            'OR' => [
                ['dateTo' => null],
                ['dateTo>' => $dateFrom],
            ],
        ];

        if ($boardSquadIds !== null) {
            $where = [
                'dateFrom<' => $dateTo,
                'AND' => [
                    ['OR' => [
                        ['dateTo' => null],
                        ['dateTo>' => $dateFrom],
                    ]],
                    ['OR' => [
                        ['boardSquadId' => $boardSquadIds],
                        ['teamId' => $teamIds ?? $boardSquadIds],
                    ]],
                ],
            ];
        }

        return $this->find($where, 'dateFrom');
    }

    /**
     * @return Entity[]
     */
    public function findForMember(string $boardMemberId, ?string $memberId = null): array
    {
        return $this->find([
            'OR' => [
                ['boardMemberId' => $boardMemberId],
                ['memberId' => $memberId ?? $boardMemberId],
            ],
        ], 'dateFrom');
    }

    /**
     * D5 — confirmed, started, never applied.
     *
     * @return Entity[]
     */
    public function findDue(string $today): array
    {
        return $this->find([
            'status' => Status::CONFIRMED,
            'dateFrom<=' => $today,
            'appliedAt' => null,
            'OR' => [
                ['dateTo' => null],
                ['dateTo>' => $today],
            ],
        ], 'dateFrom');
    }

    /**
     * A confirmed linked plan whose whole window [dateFrom, dateTo) elapsed
     * before the job could run. `findDue` and `isDue` both require the window
     * to cover today, so such a record can never be applied: applying it now
     * would place the person in a team the plan itself says they already left
     * (T06, T15). With `appliedAt` never set it is not factual history either,
     * so it stays confirmed for ever and `Zone` then refuses every edit.
     *
     * Board-only periods are excluded on purpose. They have no CRM side to
     * apply, the board renders them regardless of `appliedAt`, and their
     * elapsed confirmed periods are the board's own history (T12, T19).
     *
     * @return Entity[]
     */
    public function findExpiredUnapplied(string $today): array
    {
        return $this->find([
            'status' => Status::CONFIRMED,
            'appliedAt' => null,
            'dateTo!=' => null,
            'dateTo<=' => $today,
            'memberId!=' => null,
        ], 'dateFrom');
    }

    /**
     * Confirmed linked periods that have ended after being applied.
     *
     * @return Entity[]
     */
    public function findEndedApplied(string $today): array
    {
        return $this->find([
            'status' => Status::CONFIRMED,
            'dateTo<=' => $today,
            'appliedAt!=' => null,
            'endedAt' => null,
        ], 'dateTo');
    }

    /** The closing boundary belongs to a transition that still needs to succeed. */
    public function hasPendingReplacement(Entity $previous, string $today): bool
    {
        return $this->entityManager->getRDBRepository(self::ENTITY_TYPE)->where([
            'supersedesId' => $previous->getId(),
            'memberId' => $previous->get('memberId'),
            'dateFrom' => $previous->get('dateTo'),
            'dateFrom<=' => $today,
            'status' => Status::CONFIRMED,
            'appliedAt' => null,
            // Even a wholly missed replacement did not request a separate
            // removal from the old team. Only a successful move owns that end.
        ])->findOne() !== null;
    }

    /**
     * D15 — a draft whose start date has passed without being confirmed.
     * The cutoff is `< today`, so a draft dated today survives its own day.
     *
     * @return Entity[]
     */
    public function findMissedDrafts(string $today): array
    {
        return $this->find([
            'status' => Status::DRAFT,
            'appliedAt' => null,
            'dateFrom<' => $today,
        ], 'dateFrom');
    }

    /**
     * D3 — drafts starting within N days, for the reminder.
     *
     * @return Entity[]
     */
    public function findUpcomingDrafts(string $today, int $days): array
    {
        $until = (new \DateTimeImmutable($today))
            ->add(new \DateInterval('P' . $days . 'D'))
            ->format('Y-m-d');

        return $this->find([
            'status' => Status::DRAFT,
            'dateFrom>=' => $today,
            'dateFrom<=' => $until,
        ], 'dateFrom');
    }

    /**
     * @param array<string, mixed> $where
     * @return Entity[]
     */
    private function find(array $where, string $orderBy): array
    {
        $collection = $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->where($where)
            ->order($orderBy)
            ->order(Attribute::ID)
            ->find();

        $list = [];

        foreach ($collection as $entity) {
            $list[] = $entity;
        }

        return $list;
    }
}
