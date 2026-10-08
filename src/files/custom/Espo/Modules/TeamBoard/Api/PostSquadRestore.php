<?php

namespace Espo\Modules\TeamBoard\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TeamBoard\Tools\Shadow\ManagementService;

class PostSquadRestore implements Action
{
    public function __construct(private ManagementService $service) {}

    public function process(Request $request): Response
    {
        $id = $request->getRouteParam('id');
        if (!is_string($id) || $id === '') throw new BadRequest('Bad id.');

        $data = $request->getParsedBody();
        $viewDate = property_exists($data, 'viewDate') ? $data->viewDate : null;

        return ResponseComposer::json($this->service->restoreSquad($id, is_string($viewDate) ? $viewDate : null));
    }
}
