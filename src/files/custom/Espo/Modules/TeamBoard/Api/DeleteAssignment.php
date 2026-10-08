<?php

namespace Espo\Modules\TeamBoard\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TeamBoard\Tools\Timeline\Service;

/**
 * DELETE TeamBoard/assignment/:id?viewDate=
 *
 * @noinspection PhpUnused
 */
class DeleteAssignment implements Action
{
    public function __construct(private Service $service) {}

    public function process(Request $request): Response
    {
        $id = $request->getRouteParam('id');

        if (!is_string($id) || $id === '') {
            throw new BadRequest("Bad id.");
        }

        return ResponseComposer::json(
            $this->service->deleteAssignment($id, $request->getQueryParam('viewDate'))
        );
    }
}
