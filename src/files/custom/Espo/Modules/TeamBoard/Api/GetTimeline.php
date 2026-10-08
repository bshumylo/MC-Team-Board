<?php

namespace Espo\Modules\TeamBoard\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\DateTime;
use Espo\Modules\TeamBoard\Tools\Timeline\Service;

/**
 * GET TeamBoard/timeline?date=&from=&to=
 *
 * Composition at the requested date and assignments in the selected range.
 * The default range is the six months either side of that date.
 *
 * @noinspection PhpUnused
 */
class GetTimeline implements Action
{
    public function __construct(private Service $service, private DateTime $dateTime) {}

    public function process(Request $request): Response
    {
        $date = $request->getQueryParam('date') ?? $this->dateTime->getToday()->toString();

        if (!$this->isDate($date)) {
            throw new BadRequest("Bad date.");
        }

        $referenceDate = new \DateTimeImmutable($date);
        $from = $request->getQueryParam('from') ??
            $referenceDate->sub(new \DateInterval('P6M'))->format('Y-m-d');
        $to = $request->getQueryParam('to') ??
            $referenceDate->add(new \DateInterval('P6M'))->format('Y-m-d');

        if (!$this->isDate($from) || !$this->isDate($to)) {
            throw new BadRequest("Bad range.");
        }

        return ResponseComposer::json($this->service->getTimeline($date, $from, $to));
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return (bool) $date && $date->format('Y-m-d') === $value;
    }
}
