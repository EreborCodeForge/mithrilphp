<?php

declare(strict_types=1);

namespace Erebor\Mithril\Contracts;

use Erebor\Mithril\Container;
use Erebor\Mithril\Jobs\JobEnvelope;
use Erebor\Mithril\Jobs\JobResult;

/**
 * Application contract for the job Worker runtime.
 * Boot once; handle one job per scoped unit of work.
 */
interface JobApplication
{
    public function boot(): void;

    public function handle(JobEnvelope $job): JobResult;

    public function getContainer(): Container;
}
