<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Core\Acl\Permission;
use Espo\Core\Utils\Metadata;
use Espo\Modules\TeamBoard\Tools\Dashboard\TeamFieldMap;
use PHPUnit\Framework\TestCase;

class TeamFieldMapTest extends TestCase
{
    /**
     * @param array<string, mixed> $dashlets
     */
    private function createMap(array $dashlets): TeamFieldMap
    {
        $metadata = $this->createStub(Metadata::class);

        $metadata
            ->method('get')
            ->willReturnCallback(fn ($key) => $key === ['dashlets'] ? $dashlets : null);

        return new TeamFieldMap($metadata);
    }

    public function testCalendarLikeDashletExposesBothFields(): void
    {
        $map = $this->createMap([
            'Calendar' => [
                'aclScope' => 'Calendar',
                'options' => [
                    'fields' => [
                        'title' => ['type' => 'varchar'],
                        'users' => ['type' => 'linkMultiple', 'entity' => 'User'],
                        'teams' => ['type' => 'linkMultiple', 'entity' => 'Team'],
                    ],
                ],
            ],
        ]);

        $fields = $map->get('Calendar');

        $this->assertCount(2, $fields);

        $this->assertSame('users', $fields[0]->name);
        $this->assertSame('User', $fields[0]->entityType);
        $this->assertSame('usersIds', $fields[0]->getIdsAttribute());
        $this->assertSame('usersNames', $fields[0]->getNamesAttribute());

        $this->assertSame('teams', $fields[1]->name);
        $this->assertSame('Team', $fields[1]->entityType);
    }

    public function testCalendarFieldsAreGovernedByTheCalendarPermission(): void
    {
        $map = $this->createMap([
            'Calendar' => [
                'aclScope' => 'Calendar',
                'options' => ['fields' => ['teams' => ['type' => 'linkMultiple', 'entity' => 'Team']]],
            ],
        ]);

        $this->assertSame(Permission::USER_CALENDAR, $map->get('Calendar')[0]->permission);
    }

    public function testOtherDashletsUseTheGeneralUserPermission(): void
    {
        $map = $this->createMap([
            'Records' => [
                'aclScope' => 'Meeting',
                'options' => ['fields' => ['teams' => ['type' => 'linkMultiple', 'entity' => 'Team']]],
            ],
        ]);

        $this->assertSame(Permission::USER, $map->get('Records')[0]->permission);
    }

    public function testIrrelevantFieldsAreIgnored(): void
    {
        $map = $this->createMap([
            'Odd' => [
                'options' => [
                    'fields' => [
                        'accounts' => ['type' => 'linkMultiple', 'entity' => 'Account'],
                        'teams' => ['type' => 'multiEnum'],
                        'teamsIds' => ['type' => 'varchar'],
                    ],
                ],
            ],
        ]);

        $this->assertSame([], $map->get('Odd'));
    }

    public function testUnknownDashletAndMalformedMetadataAreSafe(): void
    {
        $map = $this->createMap([
            'Broken' => ['options' => ['fields' => 'not-an-array']],
        ]);

        $this->assertSame([], $map->get('Broken'));
        $this->assertSame([], $map->get('NoSuchDashlet'));
    }
}
