<?php

declare(strict_types=1);

namespace App\Infrastructure\View;

use App\Common\Assets\Asset;
use App\Core\View\ViewEngineInterface;

final class VueEngine implements ViewEngineInterface
{
    public function render(string $pathView): string
    {
        $compiledFile = Asset::urlFromResource($pathView);
        $content = file_get_contents($compiledFile);
        ob_start();
        include $compiledFile;
        $content = ob_get_clean();
        return $content;
    }
}
