<?php

namespace Espo\Modules\TeamBoard\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TeamBoard\Tools\Board\Service;

/**
 * POST TeamBoard/removeMember
 *
 * Payload: {userId, teamId, viewDate}. viewDate is the board's currently
 * viewed as-of date; the service rejects it when it is not today, since
 * this legacy path performs a real, present-tense CRM unrelate with no
 * dated-plan support (see Tools\Board\Service::removeMember).
 * Removes a user from a team (drag-out on the board).
 *
 * @noinspection PhpUnused
 */
class PostRemoveMember implements Action
{
    public function __construct(private Service $service) {}

    public function process(Request $request): Response
    {
        $body = $request->getParsedBody();

        $userId = $body->userId ?? null;
        $teamId = $body->teamId ?? null;
        $viewDate = $body->viewDate ?? null;

        if (!is_string($userId) || $userId === '') {
            throw new BadRequest("Bad userId.");
        }

        if (!is_string($teamId) || $teamId === '') {
            throw new BadRequest("Bad teamId.");
        }

        if ($viewDate !== null && !is_string($viewDate)) {
            throw new BadRequest("Bad viewDate.");
        }

        $data = $this->service->removeMember($userId, $teamId, $viewDate);

        return ResponseComposer::json($data);
    }
}
