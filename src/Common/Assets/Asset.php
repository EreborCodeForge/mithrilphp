<?php

declare(strict_types=1);

namespace App\Common\Assets;

final class Asset
{
    public static function urlFromPublic(string $publicPath): string
    {
        $fsPath = self::dirToFs($publicPath, 'public');
        $hash = is_file($fsPath) ? substr(sha1_file($fsPath), 0, 12) : 'dev';
        return $publicPath . '?v=' . $hash;
    }

    public static function urlFromResource(string $publicPath): string
    {
        $fsPath = self::dirToFs($publicPath, 'resources');
        return $fsPath;
    }

    private static function dirToFs(string $publicPath, string $dirName): string
    {
        $publicPath = '/' . ltrim($publicPath, '/\\');
        $root = str_replace('\\', '/', dirname(__DIR__, 3));
        return $root . '/' . $dirName . $publicPath;
    }
}
