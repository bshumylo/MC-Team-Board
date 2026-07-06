<?php

namespace Espo\Modules\TeamBoard\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Modules\TeamBoard\Tools\Board\Service;

/**
 * GET TeamBoard/data
 *
 * Returns board data: teams (columns) with their members and positions.
 *
 * @noinspection PhpUnused
 */
class GetData implements Action
{
    public function __construct(private Service $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->getData());
    }
}
