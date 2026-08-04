<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

use Erebor\Mithril\Http\Response;
use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;
use Erebor\Mithril\Runtime\Eregion\Messages\ResponseEnvelope;
use Erebor\Mithril\Runtime\Eregion\Messages\ResponseMetadata;
use Erebor\Mithril\Runtime\WorkerMetadata;

final class ResponseMapper
{
    public function map(
        string $requestId,
        Response $response,
        WorkerMetadata $metadata,
    ): ResponseEnvelope {
        $status = $response->getStatusCode();
        if ($status < 100 || $status > 599) {
            throw new ProtocolException("Invalid HTTP status code: {$status}");
        }

        return new ResponseEnvelope(
            id: $requestId,
            status: $status,
            headers: $response->getHeaders(),
            body: $response->getBodyBytes(),
            meta: new ResponseMetadata(
                requestsHandled: $metadata->requestsHandled,
                memoryUsage: $metadata->memoryUsage,
                memoryPeak: $metadata->memoryPeak,
                recycle: $metadata->recycle,
                recycleReason: $metadata->recycleReason,
            ),
        );
    }
}
