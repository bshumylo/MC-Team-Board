<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use PHPUnit\Framework\TestCase;

/**
 * F5/F8: the board edits assignments only through Timeline\Service. The generic
 * CRUD answers 404 (no controller), so the controller-less mass-action and
 * export endpoints must be disabled in metadata as well.
 */
class GenericApiLockdownTest extends TestCase
{
    private function module(): string
    {
        return dirname(__DIR__, 5) . '/src/files/custom/Espo/Modules/TeamBoard';
    }

    /**
     * @return array<string, mixed>
     */
    private function recordDefs(string $entityType): array
    {
        $path = $this->module() . '/Resources/metadata/recordDefs/' . $entityType . '.json';
        $this->assertFileExists($path);
        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data);

        return $data;
    }

    public function testAssignmentMassActionsAndExportAreDisabled(): void
    {
        $defs = $this->recordDefs('TeamBoardAssignment');

        $this->assertTrue($defs['massActionsDisabled'] ?? false);
        $this->assertTrue($defs['exportDisabled'] ?? false);
    }

    public function testMemberMassActionsAndExportAreDisabled(): void
    {
        $defs = $this->recordDefs('TeamBoardMember');

        $this->assertTrue($defs['massActionsDisabled'] ?? false);
        $this->assertTrue($defs['exportDisabled'] ?? false);
    }

    public function testAssignmentHasNoGenericController(): void
    {
        // Without a controller the core answers plain CRUD with 404; adding one
        // would open the generic API around Timeline\Service.
        $this->assertFileDoesNotExist($this->module() . '/Controllers/TeamBoardAssignment.php');
    }
}
