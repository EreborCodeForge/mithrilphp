<?php

declare(strict_types=1);

namespace Erebor\Mithril\Support;

final class PackageVersion
{
    private static ?string $mithril = null;

    public static function mithril(): string
    {
        if (self::$mithril !== null) {
            return self::$mithril;
        }

        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                self::$mithril = \Composer\InstalledVersions::getPrettyVersion('ereborcodeforge/mithrilphp')
                    ?? \Composer\InstalledVersions::getVersion('ereborcodeforge/mithrilphp')
                    ?? 'dev';
                return self::$mithril;
            } catch (\Throwable) {
                // fall through
            }
        }

        return self::$mithril = 'dev';
    }
}
