<?php

declare(strict_types=1);

namespace Erebor\Mithril\Runtime\Eregion\Messages;

use Erebor\Mithril\Runtime\Eregion\Protocol;

final readonly class ResponseMetadata
{
    public function __construct(
        public int $requestsHandled,
        public int $memoryUsage,
        public int $memoryPeak,
        public bool $recycle = false,
        public ?string $recycleReason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $meta = [
            'requests_handled' => $this->requestsHandled,
            'memory_usage' => $this->memoryUsage,
            'memory_peak' => $this->memoryPeak,
            'recycle' => $this->recycle,
        ];

        if ($this->recycleReason !== null && $this->recycleReason !== '') {
            $meta['recycle_reason'] = $this->recycleReason;
        }

        return $meta;
    }
}
