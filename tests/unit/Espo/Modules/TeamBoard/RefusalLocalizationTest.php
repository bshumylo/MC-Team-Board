<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Language;
use Espo\Modules\TeamBoard\Tools\Support\LocalizedDenial;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Backlog Q4 item 3 / 2026-10-19 item 5: every refusal a user can trigger from
 * the UI is a localized label (ASCII English in X-Status-Reason, the user's
 * language in the body), and no user-visible English text says "EspoCRM".
 * NotFound is not covered: the core NotFound cannot carry a body, and the
 * client never shows its reason.
 */
class RefusalLocalizationTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../src/files/custom/Espo/Modules/TeamBoard';

    /** Files whose BadRequest refusals are all user-reachable except the allowlisted ones. */
    private const GUARDED = [
        'Tools/Board/Service.php',
        'Tools/Shadow/ManagementService.php',
        'Tools/Shadow/PhotoService.php',
        'Tools/Timeline/AssignmentEditor.php',
        'Tools/Timeline/Service.php',
    ];

    /** Developer-facing validation of malformed requests (not reachable from the UI). */
    private const DEVELOPER_FACING = [
        '/^Bad [\w$]+\.$/',
        '/^Unsupported field: /',
        '/^Dates must use YYYY-MM-DD\.$/',
        '/^Bad board photo (payload|data URL)\.$/',
    ];

    /** @return array<string, array<string, string>> */
    private function messages(string $language): array
    {
        $file = self::MODULE . "/Resources/i18n/$language/TeamBoard.json";
        $data = json_decode((string) file_get_contents($file), true);

        $this->assertIsArray($data, "$language TeamBoard.json must be valid JSON");

        return $data['messages'];
    }

    /** @return string[] */
    private function phpFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::MODULE));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @return string[] Labels (bad_* / deny_*) used by localized refusals in src. */
    private function usedLabels(): array
    {
        $labels = [];

        foreach ($this->phpFiles() as $path) {
            $source = (string) file_get_contents($path);

            if (str_ends_with($path, 'LocalizedDenial.php')) {
                continue;
            }

            preg_match_all('/->(badRequest)\(\s*\'(\w+)\'\s*\)/', $source, $bad);
            preg_match_all('/->(forbidden|denial)\(\s*\'(\w+)\'\s*\)/', $source, $deny);

            foreach ($bad[2] as $key) {
                $labels['bad_' . $key] = true;
            }
            foreach ($deny[2] as $key) {
                $labels['deny_' . $key] = true;
            }
        }

        return array_keys($labels);
    }

    public function testEveryUsedLabelExistsInBothLanguages(): void
    {
        $en = $this->messages('en_US');
        $uk = $this->messages('uk_UA');
        $used = $this->usedLabels();

        $this->assertNotEmpty($used);

        foreach ($used as $label) {
            $this->assertArrayHasKey($label, $en, "en_US is missing $label");
            $this->assertArrayHasKey($label, $uk, "uk_UA is missing $label");
            $this->assertNotSame('', trim($uk[$label]), "uk_UA $label is empty");
        }
    }

    public function testEnglishRefusalTextIsPrintableAsciiAndNeverNamesEspoCrm(): void
    {
        foreach ($this->messages('en_US') as $label => $text) {
            if (!str_starts_with($label, 'bad_') && !str_starts_with($label, 'deny_')) {
                continue;
            }

            $this->assertSame(1, preg_match('/^[\x20-\x7E]+$/', $text), "$label must be printable ASCII");
            $this->assertStringNotContainsStringIgnoringCase('EspoCRM', $text, "$label must say \"system\"");
        }
    }

    public function testUkrainianRefusalTextDoesNotSayEspoCrm(): void
    {
        foreach ($this->messages('uk_UA') as $label => $text) {
            if (str_starts_with($label, 'bad_') || str_starts_with($label, 'deny_')) {
                $this->assertStringNotContainsStringIgnoringCase('EspoCRM', $text, "$label must say \"system\"");
            }
        }
    }

    public function testUserReachableRefusalsAreNotPlainEnglishExceptions(): void
    {
        foreach (self::GUARDED as $relative) {
            $source = (string) file_get_contents(self::MODULE . '/' . $relative);

            preg_match_all('/new BadRequest\(\s*(["\'])(.*?)\1/s', $source, $matches);

            foreach ($matches[2] as $text) {
                $allowed = false;

                foreach (self::DEVELOPER_FACING as $pattern) {
                    if (preg_match($pattern, $text) === 1) {
                        $allowed = true;
                    }
                }

                $this->assertTrue($allowed, "$relative throws a plain English refusal: $text");
            }
        }
    }

    public function testBadRequestCarriesAsciiReasonAndTranslationLabel(): void
    {
        $subject = new class {
            use LocalizedDenial;

            public Language $language;

            public function make(string $key): BadRequest
            {
                return $this->badRequest($key);
            }
        };

        $e = $subject->make('teamHasAssignments');
        $body = json_decode((string) $e->getBody(), true);

        $this->assertSame('A team with current or future assignments cannot be archived.', $e->getMessage());
        $this->assertSame('bad_teamHasAssignments', $body['messageTranslation']['label']);
        $this->assertSame('TeamBoard', $body['messageTranslation']['scope']);
    }
}
