<?php

namespace Espo\Modules\TeamBoard\Tools\Support;

use Espo\Core\Utils\Language;

/**
 * Localized denial reason for callers that cannot take `Language` by
 * constructor (the generic TeamBoardMember controller).
 */
class Denial
{
    use LocalizedDenial {
        forbidden as public;
    }

    public function __construct(private Language $language)
    {
    }

    public function text(string $key): string
    {
        return $this->denial($key);
    }
}
