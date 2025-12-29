<?php

declare(strict_types=1);

namespace Erebor\Mithril\Core\View;

interface ViewEngineInterface
{
    public function render(string $pathView): string;
}