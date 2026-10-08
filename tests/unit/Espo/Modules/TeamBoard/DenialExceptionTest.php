<?php

namespace tests\unit\Espo\Modules\TeamBoard;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Language;
use Espo\Modules\TeamBoard\Tools\Support\Denial;
use PHPUnit\Framework\TestCase;

/**
 * P14/U19: Espo writes the exception message to X-Status-Reason as raw UTF-8,
 * so the message must be ASCII and the localized text travels in the body.
 */
class DenialExceptionTest extends TestCase
{
    private function denial(): Denial
    {
        $language = $this->createStub(Language::class);
        $language->method('translate')->willReturn('У вас немає доступу до Team Board.');

        return new Denial($language);
    }

    public function testForbiddenCarriesTranslationLabelInBody(): void
    {
        $e = $this->denial()->forbidden('noAccess');

        $this->assertInstanceOf(Forbidden::class, $e);

        $body = json_decode((string) $e->getBody(), true);

        $this->assertSame('deny_noAccess', $body['messageTranslation']['label']);
        $this->assertSame('TeamBoard', $body['messageTranslation']['scope']);
    }

    public function testMessageIsAsciiEnglishEvenForUkrainianUser(): void
    {
        $e = $this->denial()->forbidden('noAccess');

        $this->assertSame('No access to Team Board.', $e->getMessage());
        $this->assertSame(1, preg_match('/^[\x20-\x7E]+$/', $e->getMessage()));
    }

    public function testUnknownKeyFallsBackToKey(): void
    {
        $this->assertSame('nope', $this->denial()->forbidden('nope')->getMessage());
    }
}
