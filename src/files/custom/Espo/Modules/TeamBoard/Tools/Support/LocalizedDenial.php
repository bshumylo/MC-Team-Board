<?php

namespace Espo\Modules\TeamBoard\Tools\Support;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error\Body;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Language;

/**
 * P14/U19: API denial reasons are read from the module i18n, so a Ukrainian
 * user does not get English text. Requires a `$language` property.
 *
 * @property Language $language
 */
trait LocalizedDenial
{
    private function denial(string $key): string
    {
        $text = $this->language->translate('deny_' . $key, 'messages', 'TeamBoard');

        return is_string($text) ? $text : $key;
    }

    /**
     * Espo prints the exception message to the X-Status-Reason header as raw
     * UTF-8, so the message must be ASCII (the English text). The user's
     * language is served by the client from the body's messageTranslation label.
     */
    private function forbidden(string $key): Forbidden
    {
        return Forbidden::createWithBody(
            $this->asciiDenial('deny_' . $key, $key),
            Body::create()->withMessageTranslation('deny_' . $key, 'TeamBoard')
        );
    }

    /**
     * A 400 refusal of invalid input, worded the same way as a denial: ASCII
     * reason in the header, localized label in the body.
     */
    private function badRequest(string $key): BadRequest
    {
        return BadRequest::createWithBody(
            $this->asciiDenial('bad_' . $key, $key),
            Body::create()->withMessageTranslation('bad_' . $key, 'TeamBoard')
        );
    }

    private function asciiDenial(string $label, string $key): string
    {
        $file = dirname(__DIR__, 2) . '/Resources/i18n/en_US/TeamBoard.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $text = $data['messages'][$label] ?? null;

        if (!is_string($text) || preg_match('/^[\x20-\x7E]+$/', $text) !== 1) {
            return $key;
        }

        return $text;
    }
}
