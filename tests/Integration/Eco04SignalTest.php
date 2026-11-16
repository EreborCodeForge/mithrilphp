<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class Eco04SignalTest extends TestCase
{
    public function testIdleSigtermStopsWithoutKill(): void
    {
        $this->requireSignals();
        $dir = $this->markerDir();
        $process = $this->start($dir, false);
        $this->waitFor($dir . '/ready', 3.0);
        posix_kill($process['pid'], SIGTERM);
        $exit = $this->waitExit($process['process'], 3.0);
        $this->assertSame(0, $exit, (string) @file_get_contents($dir . '/stderr'));
        $this->assertFileDoesNotExist($dir . '/finished');
    }

    public function testBusySigtermFinishesTheCurrentJob(): void
    {
        $this->requireSignals();
        $dir = $this->markerDir();
        $process = $this->start($dir, true);
        $this->waitFor($dir . '/handling', 3.0);
        posix_kill($process['pid'], SIGTERM);
        $exit = $this->waitExit($process['process'], 4.0);
        $this->assertSame(0, $exit, (string) @file_get_contents($dir . '/stderr'));
        $this->assertFileExists($dir . '/finished');
    }

    private function requireSignals(): void
    {
        if (!function_exists('posix_kill') || !function_exists('pcntl_signal')) {
            $this->markTestSkipped('SIGTERM delivery requires the posix and pcntl extensions');
        }
    }

    private function markerDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eco04_' . uniqid('', true);
        mkdir($dir, 0777, true);

        return $dir;
    }

    /**
     * @return array{process: resource, pid: int}
     */
    private function start(string $dir, bool $busy): array
    {
        $script = dirname(__DIR__) . '/Fixtures/eco04signal/worker.php';
        $env = $busy ? ['ECO04_BUSY' => '1'] : [];
        $process = proc_open(
            [PHP_BINARY, $script, $dir],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/stdout', 'w'], 2 => ['file', $dir . '/stderr', 'w']],
            $pipes,
            null,
            $env,
            ['bypass_shell' => true],
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $status = proc_get_status($process);

        return ['process' => $process, 'pid' => (int) $status['pid'], 'pipes' => $pipes];
    }

    private function waitFor(string $path, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if (is_file($path)) {
                return;
            }
            usleep(20000);
        }
        $this->fail('timed out waiting for ' . $path);
    }

    private function waitExit(mixed $process, float $seconds): int
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
        $status = proc_get_status($process);
            if (is_array($status) && !$status['running']) {
                $code = (int) $status['exitcode'];
                proc_close($process);
                if ($code < 0 && (int) ($status['termsig'] ?? 0) > 0) {
                    $code = 128 + (int) $status['termsig'];
                }

                return $code;
            }
            usleep(20000);
        }
        $status = proc_get_status($process);
        $pid = is_array($status) ? (int) ($status['pid'] ?? 0) : 0;
        if ($pid > 0) {
            posix_kill($pid, SIGKILL);
        }
        proc_close($process);
        $this->fail('worker did not exit before the deadline');
    }
}
