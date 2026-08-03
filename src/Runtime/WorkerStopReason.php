<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime;

enum WorkerStopReason: string
{
    case Stopped = 'stopped';
    case Recycled = 'recycled';
    case RemoteShutdown = 'remote_shutdown';
    case ProtocolFailure = 'protocol_failure';
    case ScopeCleanupFailure = 'scope_cleanup_failure';
    case BootstrapFailure = 'bootstrap_failure';
}
