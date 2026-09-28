<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Runtime;

use Erebor\Mithril\Runtime\EregionWorkloadMetadata;
use PHPUnit\Framework\TestCase;

final class EregionWorkloadMetadataTest extends TestCase
{
    public function testFromEnvironmentReadsOptionalVars(): void
    {
        putenv('EREGION_WORKLOAD=jobs');
        putenv('EREGION_WORKER_ID=w-9');
        putenv('EREGION_GENERATION=4');

        try {
            $meta = EregionWorkloadMetadata::fromEnvironment();
            $this->assertTrue($meta->isPresent());
            $this->assertSame('jobs', $meta->workload);
            $this->assertSame('w-9', $meta->workerId);
            $this->assertSame('4', $meta->generation);
        } finally {
            putenv('EREGION_WORKLOAD');
            putenv('EREGION_WORKER_ID');
            putenv('EREGION_GENERATION');
        }
    }

    public function testAbsentWhenUnset(): void
    {
        putenv('EREGION_WORKLOAD');
        putenv('EREGION_WORKER_ID');
        putenv('EREGION_GENERATION');

        $meta = EregionWorkloadMetadata::fromEnvironment();
        $this->assertFalse($meta->isPresent());
    }
}
