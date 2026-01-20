<?php

declare(strict_types=1);

namespace Erebor\Mithril\Contracts;

interface TraceIdGeneratorContract
{
    public function generate(): string;
}
