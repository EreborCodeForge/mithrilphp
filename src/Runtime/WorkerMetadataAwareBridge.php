<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

use Erebor\Mithril\Http\Response;

/**
 * Bridge that can attach worker recycle/memory metadata to each response.
 */
interface WorkerMetadataAwareBridge extends RequestBridge
{
    public function respond(Response $response, ?WorkerMetadata $metadata = null): void;
}
