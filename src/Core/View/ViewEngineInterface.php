<?php

declare(strict_types=1);

namespace App\Core\View;

interface ViewEngineInterface
{
    public function render(string $pathView): string;
}