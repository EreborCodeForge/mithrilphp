<?php

declare(strict_types=1);

namespace Erebor\Mithril\View;

interface ViewEngineInterface
{
    public function render(string $pathView): string;
}