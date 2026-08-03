<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion;

use Erebor\Mithril\Contracts\HttpApplication;
use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;
use Erebor\Mithril\Runtime\Recycling\CompositeRecyclingPolicy;
use Erebor\Mithril\Runtime\Recycling\MaxRequestsPolicy;
use Erebor\Mithril\Runtime\Recycling\MemoryLimitPolicy;
use Erebor\Mithril\Runtime\Worker;
use Erebor\Mithril\Runtime\WorkerResult;
use Erebor\Mithril\Runtime\WorkerStopReason;
use Erebor\Mithril\Support\PackageVersion;
use Throwable;

/**
 * Boots the application and runs the Eregion worker loop.
 */
final class WorkerLauncher
{
    public function run(WorkerCliOptions $options): WorkerResult
    {
        try {
            $manifest = Manifest::fromFile($options->manifest);

            if ($manifest->workingDirectory !== '' && is_dir($manifest->workingDirectory)) {
                chdir($manifest->workingDirectory);
            }

            $autoload = $this->resolvePath($manifest->autoload, $manifest->workingDirectory);
            if (!is_file($autoload)) {
                throw new ProtocolException("Autoload not found: {$autoload}");
            }
            require_once $autoload;

            $applicationClass = $manifest->application;
            if (!class_exists($applicationClass)) {
                throw new ProtocolException("Application class not found: {$applicationClass}");
            }

            $app = new $applicationClass();
            if (!$app instanceof HttpApplication) {
                throw new ProtocolException("Application must implement HttpApplication: {$applicationClass}");
            }

            $maxRequests = $options->maxRequests;
            $memoryLimitBytes = $options->memoryLimitBytes();
            // CLI flags are the operational source; 0 means disabled (not "use manifest")
            // Manifest defaults only apply when building Forge config — worker receives CLI from Eregion.

            $bridge = new EregionBridge(
                socketPath: $options->socket,
                workerId: $options->workerId,
                generation: $options->generation,
                maxFrameBytes: $manifest->maxFrameBytes(),
                mithrilVersion: PackageVersion::mithril(),
            );

            $policy = new CompositeRecyclingPolicy(
                new MaxRequestsPolicy($maxRequests),
                new MemoryLimitPolicy($memoryLimitBytes),
            );

            $worker = new Worker(
                app: $app,
                bridge: $bridge,
                maxRequests: $maxRequests,
                recyclingPolicy: $policy,
                memoryLimitBytes: $memoryLimitBytes,
            );

            try {
                return $worker->runResult();
            } finally {
                $bridge->close();
            }
        } catch (ProtocolException $e) {
            // Distinguish handshake/protocol vs bootstrap by message class hierarchy
            if ($e instanceof Exceptions\HandshakeException
                || $e instanceof Exceptions\InvalidFrameException
                || $e instanceof Exceptions\FrameTooLargeException
                || $e instanceof Exceptions\UnsupportedProtocolVersionException
                || $e instanceof Exceptions\UnexpectedMessageException
                || $e instanceof Exceptions\ConnectionClosedException
            ) {
                return new WorkerResult(0, WorkerStopReason::ProtocolFailure);
            }

            return new WorkerResult(0, WorkerStopReason::BootstrapFailure);
        } catch (Throwable) {
            return new WorkerResult(0, WorkerStopReason::BootstrapFailure);
        }
    }

    public function exitCode(WorkerResult $result): int
    {
        return $result->exitCode()->value;
    }

    private function resolvePath(string $path, string $workingDirectory): string
    {
        if ($path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1)) {
            return $path;
        }

        return rtrim($workingDirectory, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}
