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
 * Payload: {userId, teamId}.
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

        if (!is_string($userId) || $userId === '') {
            throw new BadRequest("Bad userId.");
        }

        if (!is_string($teamId) || $teamId === '') {
            throw new BadRequest("Bad teamId.");
        }

        $data = $this->service->removeMember($userId, $teamId);

        return ResponseComposer::json($data);
    }
}
