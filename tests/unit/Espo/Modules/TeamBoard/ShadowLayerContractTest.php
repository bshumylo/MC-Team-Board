<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Modules\TeamBoard\Tools\Shadow\Backfill;
use Espo\Modules\TeamBoard\Tools\Shadow\Registry;
use PHPUnit\Framework\TestCase;

class ShadowLayerContractTest extends TestCase
{
    public function testShadowServicesAndMetadataExist(): void
    {
        $root = dirname(__DIR__, 5);

        $this->assertTrue(class_exists(Backfill::class), 'Backfill service is missing.');
        $this->assertTrue(class_exists(Registry::class), 'Registry service is missing.');
        $this->assertFileExists(
            $root . '/src/files/custom/Espo/Modules/TeamBoard/Resources/metadata/entityDefs/TeamBoardMember.json'
        );
        $this->assertFileExists(
            $root . '/src/files/custom/Espo/Modules/TeamBoard/Resources/metadata/entityDefs/TeamBoardSquad.json'
        );
    }

    public function testBackfillWritesSkipHooksForCliInstallContext(): void
    {
        $root = dirname(__DIR__, 5);
        $source = file_get_contents(
            $root . '/src/files/custom/Espo/Modules/TeamBoard/Tools/Shadow/Backfill.php'
        );

        $this->assertIsString($source);
        $this->assertStringContainsString('SaveOption::SKIP_HOOKS', $source);
    }
}
