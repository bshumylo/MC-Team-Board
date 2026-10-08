<?php

namespace Espo\Modules\TeamBoard\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Modules\TeamBoard\Tools\Shadow\ManagementService;

class GetArchivedSquads implements Action
{
    public function __construct(private ManagementService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->archivedSquads($request->getQueryParam('viewDate')));
    }
}
