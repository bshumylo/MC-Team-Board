<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\TeamBoard\Hooks\Team\ResetFuturePlans as TeamHook;
use Espo\Modules\TeamBoard\Hooks\User\ResetFuturePlans as UserHook;
use Espo\Modules\TeamBoard\Tools\Timeline\FuturePlanResetter;
use Espo\Modules\TeamBoard\Tools\Timeline\CrmChangeHandler;
use Espo\ORM\Repository\Option\RelateOptions;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Repository\Option\UnrelateOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CrmFuturePlanHooksTest extends TestCase
{
    public static function saves(): array
    {
        return [
            'default changed or cleared' => ['defaultTeamId', false, false, 'regular', true],
            'memberships changed' => ['teamsIds', false, false, 'regular', true],
            'activation changed' => ['isActive', false, false, 'regular', true],
            'admin person' => ['defaultTeamId', false, false, 'admin', true],
            'profile edit' => ['firstName', false, false, 'regular', false],
            'planned transition' => ['defaultTeamId', true, false, 'regular', false],
            'new person' => ['defaultTeamId', false, true, 'regular', false],
            'portal user' => ['defaultTeamId', false, false, 'portal', false],
        ];
    }

    #[DataProvider('saves')]
    public function testRelevantManualSavesOnly(string $field, bool $planned, bool $new, string $type, bool $reset): void
    {
        $user = $this->user($type);
        $user->method('isNew')->willReturn($new);
        $user->method('isAttributeChanged')->willReturnCallback(static fn ($name) => $name === $field);
        $resetter = $this->resetter($reset);

        (new UserHook($resetter))->afterSave($user, SaveOptions::fromAssoc([
            FuturePlanResetter::SKIP_OPTION => $planned,
        ]));
    }

    public static function relations(): iterable
    {
        foreach (['user', 'team'] as $side) {
            foreach (['relate', 'unrelate'] as $action) {
                yield "$side $action manual" => [$side, $action, false, true, true];
                yield "$side $action planned" => [$side, $action, true, true, false];
                yield "$side $action unrelated link" => [$side, $action, false, false, false];
            }
        }
    }

    #[DataProvider('relations')]
    public function testNativeMembershipEvents(string $side, string $action, bool $planned, bool $membership, bool $reset): void
    {
        $user = $this->user('regular');
        $team = $this->createStub(Team::class);
        $resetter = $this->resetter($reset);
        $hook = $side === 'user' ? new UserHook($resetter) : new TeamHook($resetter);
        $entity = $side === 'user' ? $user : $team;
        $related = $side === 'user' ? $team : $user;
        $relation = $membership ? ($side === 'user' ? 'teams' : 'users') : 'roles';
        $options = [FuturePlanResetter::SKIP_OPTION => $planned];

        if ($action === 'relate') {
            $hook->afterRelate($entity, $relation, $related, [], RelateOptions::fromAssoc($options));
        } else {
            $hook->afterUnrelate($entity, $relation, $related, UnrelateOptions::fromAssoc($options));
        }
    }

    private function user(string $type): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn('crm-user');
        $user->method('get')->willReturnCallback(static fn ($name) => $name === 'type' ? $type : null);
        return $user;
    }

    private function resetter(bool $reset): CrmChangeHandler
    {
        $resetter = $this->createMock(CrmChangeHandler::class);
        $resetter->expects($reset ? $this->once() : $this->never())->method('handle')
            ->with('crm-user');
        return $resetter;
    }
}
