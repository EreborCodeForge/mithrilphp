<?php

declare(strict_types=1);

namespace Erebor\Mithril\Contracts;

use Erebor\Mithril\Http\HttpContext;

interface PipelineContract
{
    public function send(HttpContext $request): self;
    public function through(array $middlewares): self;
    public function then(callable $destination): mixed;
}
