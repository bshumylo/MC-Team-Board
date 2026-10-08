<?php

namespace Espo\Modules\TeamBoard\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Controllers\RecordBase;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\TeamBoard\Tools\Support\Denial;
use stdClass;

/**
 * P13: the «Manage users» page is a native read-only list of board people.
 * Records change only through the checked board actions (/TeamBoard/member…),
 * so every generic Record API mutation is refused here as well as by the ACL.
 */
class TeamBoardMember extends RecordBase
{
    private function forbidden(string $key): Forbidden
    {
        return $this->injectableFactory->create(Denial::class)->forbidden($key);
    }

    public function postActionCreate(Request $request, Response $response): stdClass
    {
        throw $this->forbidden('memberCreate');
    }

    public function patchActionUpdate(Request $request, Response $response): stdClass
    {
        throw $this->forbidden('memberEdit');
    }

    public function putActionUpdate(Request $request, Response $response): stdClass
    {
        throw $this->forbidden('memberEdit');
    }

    public function deleteActionDelete(Request $request, Response $response): bool
    {
        throw $this->forbidden('memberArchive');
    }

    public function postActionGetDuplicateAttributes(Request $request): stdClass
    {
        throw new Forbidden();
    }

    public function postActionRestoreDeleted(Request $request): bool
    {
        throw new Forbidden();
    }
}
