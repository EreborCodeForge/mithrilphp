<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

enum WorkerExitCode: int
{
    case Normal = 0;
    case Recycled = 10;
    case BootstrapFailure = 20;
    case ProtocolFailure = 21;
    case ScopeCleanupFailure = 22;

    public static function fromStopReason(WorkerStopReason $reason): self
    {
        return match ($reason) {
            WorkerStopReason::Stopped, WorkerStopReason::RemoteShutdown => self::Normal,
            WorkerStopReason::Recycled => self::Recycled,
            WorkerStopReason::BootstrapFailure => self::BootstrapFailure,
            WorkerStopReason::ProtocolFailure => self::ProtocolFailure,
            WorkerStopReason::ScopeCleanupFailure => self::ScopeCleanupFailure,
        };
    }
}
