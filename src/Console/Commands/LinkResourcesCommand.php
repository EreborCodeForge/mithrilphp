<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;

class LinkResourcesCommand extends Command
{
    public function execute(): int
    {
        $root = $this->projectRoot();

        $target = $this->joinPath($root, 'resources');
        $link   = $this->joinPath($root, 'public', 'resources');

        if (!is_dir($target)) {
            $this->error('The "resources" directory does not exist: ' . $target);
            return 1;
        }

        if (file_exists($link) || is_link($link)) {
            $backup = $link . '_backup_' . date('Ymd_His');

            if (!$this->renamePath($link, $backup)) {
                $this->error("Failed to rename existing path \"$link\" to \"$backup\".");
                return 1;
            }

            $this->info("Existing \"public/resources\" renamed to \"$backup\".");
        }

        if (@symlink($target, $link)) {
            $this->info('Linked via symlink: public/resources -> resources');
            return 0;
        }

        if ($this->isWindows()) {
            if ($this->createWindowsJunction($target, $link)) {
                $this->info('Linked via Windows junction: public/resources -> resources');
                return 0;
            }

            $this->error(
                "Failed to create link on Windows.\n" .
                "Tip: enable Developer Mode or run terminal as Administrator to allow symlink()."
            );
            return 1;
        }

        $this->error('Failed to create symbolic link (symlink returned false).');
        return 1;
    }

    public static function getSignature(): string
    {
        return 'link:resources';
    }

    public static function getDescription(): string
    {
        return 'Create a link from "public/resources" to "resources" (cross-platform)';
    }

    private function projectRoot(): string
    {
        $root = dirname(__DIR__, 3);
        return $this->normalizePath($root);
    }

    private function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        return rtrim($path, '/');
    }

    private function joinPath(string ...$parts): string
    {
        $clean = [];

        foreach ($parts as $p) {
            if ($p === '') continue;
            $clean[] = trim(str_replace('\\', '/', $p), '/');
        }

        if (preg_match('#^[A-Za-z]:$#', $clean[0] ?? '')) {
            $drive = array_shift($clean);
            return $drive . '/' . implode('/', $clean);
        }

        return implode('/', $clean);
    }

    private function renamePath(string $from, string $to): bool
    {
        if (@rename($from, $to)) {
            return true;
        }

        return false;
    }

    private function createWindowsJunction(string $target, string $link): bool
    {
        $cmd = sprintf(
            'cmd /c mklink /J "%s" "%s"',
            str_replace('/', '\\', $link),
            str_replace('/', '\\', $target)
        );

        @exec($cmd, $out, $code);

        return $code === 0 && (is_dir($link) || is_link($link));
    }
}
