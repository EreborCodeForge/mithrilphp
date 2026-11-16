<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

use Erebor\Mithril\Runtime\Eregion\Exceptions\EregionBinaryException;

/**
 * Runs `eregion version` without a shell and checks pin + protocol.
 */
final class EregionBinaryVersionInspector
{
    public function __construct(
        private readonly float $timeoutSeconds = 2.0,
    ) {}

    /**
     * @return array{version: string, protocol: string}
     */
    public function inspect(string $binary): array
    {
        $stdout = $this->runVersion($binary);
        $lines = array_values(array_filter(
            $this->significantLines($stdout),
            static fn (string $line): bool => preg_match('/^go\s+\S+/', $line) !== 1,
        ));
        if (count($lines) !== 2) {
            throw new EregionBinaryException(
                'Eregion version stdout is malformed (expected exactly "eregion <semver>" and "protocol eregion/1"): '
                . $this->excerpt($stdout)
            );
        }

        if (!preg_match('/^eregion\s+v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.]+)?)$/i', $lines[0], $versionMatch)) {
            throw new EregionBinaryException(
                'Eregion version line is malformed: ' . $this->excerpt($lines[0])
            );
        }

        if (!preg_match('/^protocol\s+(\S+)$/i', $lines[1], $protocolMatch)) {
            throw new EregionBinaryException(
                'Eregion protocol line is malformed: ' . $this->excerpt($lines[1])
            );
        }

        $protocol = $protocolMatch[1];
        if ($protocol !== EregionRelease::PROTOCOL) {
            throw new EregionBinaryException(
                'Eregion protocol mismatch: required ' . EregionRelease::PROTOCOL . ', got ' . $protocol
            );
        }

        return [
            'version' => $versionMatch[1],
            'protocol' => $protocol,
        ];
    }

    /**
     * @return array{version: string, protocol: string}
     */
    public function assertCompatible(string $binary, string $requiredVersion): array
    {
        $info = $this->inspect($binary);
        if (EregionRelease::compareVersions($info['version'], $requiredVersion) !== 0) {
            throw new EregionBinaryException(
                'Eregion version mismatch: required ' . EregionRelease::normalizeVersion($requiredVersion)
                . ', installed ' . $info['version']
                . '. Use --force or point EREGION_BINARY and the project pin at the same release.'
            );
        }

        return $info;
    }

    private function runVersion(string $binary): string
    {
        if ($this->timeoutSeconds <= 0) {
            throw new EregionBinaryException('Eregion version timeout must be greater than zero');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [$binary, 'version'],
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );

        if (!is_resource($process)) {
            throw new EregionBinaryException('Unable to execute Eregion binary: ' . $binary);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $status = proc_get_status($process);
        $pid = is_int($status['pid'] ?? null) ? $status['pid'] : 0;
        $stdout = '';
        $stderr = '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $deadline = microtime(true) + $this->timeoutSeconds;
        $selectTimedOut = false;

        while ($open !== [] && microtime(true) < $deadline) {
            $read = array_values($open);
            $write = null;
            $except = null;
            $left = max(0.0, $deadline - microtime(true));
            $seconds = (int) floor($left);
            $micros = (int) round(($left - $seconds) * 1000000);
            $ready = @stream_select($read, $write, $except, $seconds, $micros);
            if ($ready === false || $ready === 0) {
                $selectTimedOut = true;
                break;
            }
            foreach ($read as $pipe) {
                $chunk = fread($pipe, 8192);
                $index = array_search($pipe, $open, true);
                if ($chunk === false || $chunk === '') {
                    if ($index !== false) {
                        unset($open[$index]);
                    }
                    continue;
                }
                if ($index === 1) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        $timedOut = $selectTimedOut && $stdout === '' && $stderr === '';
        if ($timedOut || $open !== []) {
            $this->killProcess($process, $pid);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($timedOut) {
            throw new EregionBinaryException(
                'Eregion version timed out after ' . $this->timeoutSeconds . 's: ' . $binary
            );
        }

        if ($exitCode !== 0) {
            throw new EregionBinaryException(
                'Eregion version exited ' . $exitCode . ': ' . $this->excerpt($stderr !== '' ? $stderr : $stdout)
            );
        }

        return $stdout;
    }

    /**
     * @return list<string>
     */
    private function significantLines(string $stdout): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function killProcess(mixed $process, int $pid): void
    {
        if ($pid > 0 && PHP_OS_FAMILY === 'Windows') {
            exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');
        }
        if (is_resource($process)) {
            proc_terminate($process);
        }
    }

    private function excerpt(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (strlen($text) > 180) {
            return substr($text, 0, 180) . '...';
        }

        return $text === '' ? '(empty)' : $text;
    }
}
