<?php

namespace Espo\Modules\TeamBoard\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TeamBoard\Tools\Board\Service;

/**
 * POST TeamBoard/move
 *
 * Payload: {userId, teamId, fromTeamId?, position}.
 * Moves a user to a team (or changes the position within a team).
 *
 * @noinspection PhpUnused
 */
class PostMove implements Action
{
    public function __construct(private Service $service) {}

    public function process(Request $request): Response
    {
        $body = $request->getParsedBody();

        $userId = $body->userId ?? null;
        $teamId = $body->teamId ?? null;
        $fromTeamId = $body->fromTeamId ?? null;
        $position = $body->position ?? null;

        if (!is_string($userId) || $userId === '') {
            throw new BadRequest("Bad userId.");
        }

        if (!is_string($teamId) || $teamId === '') {
            throw new BadRequest("Bad teamId.");
        }

        if ($fromTeamId !== null && !is_string($fromTeamId)) {
            throw new BadRequest("Bad fromTeamId.");
        }

        if (!is_string($position) || $position === '') {
            throw new BadRequest("Bad position.");
        }

        $data = $this->service->move($userId, $teamId, $fromTeamId, $position);

        return ResponseComposer::json($data);
    }
}
