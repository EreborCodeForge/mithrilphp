<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Runtime;

use Erebor\Mithril\Container;
use Erebor\Mithril\Contracts\JobApplication;
use Erebor\Mithril\Jobs\JobEnvelope;
use Erebor\Mithril\Jobs\JobResult;
use Erebor\Mithril\Runtime\JobApplicationResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JobApplicationResolverTest extends TestCase
{
    private string $tmpDir;
    private ?string $prevEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mithril-job-resolver-' . uniqid('', true);
        mkdir($this->tmpDir);
        $env = getenv('MITHRIL_JOB_KERNEL');
        $this->prevEnv = $env === false ? null : $env;
        putenv('MITHRIL_JOB_KERNEL');
        unset($_ENV['MITHRIL_JOB_KERNEL'], $_SERVER['MITHRIL_JOB_KERNEL']);
    }

    protected function tearDown(): void
    {
        if ($this->prevEnv === null) {
            putenv('MITHRIL_JOB_KERNEL');
            unset($_ENV['MITHRIL_JOB_KERNEL'], $_SERVER['MITHRIL_JOB_KERNEL']);
        } else {
            putenv('MITHRIL_JOB_KERNEL=' . $this->prevEnv);
            $_ENV['MITHRIL_JOB_KERNEL'] = $this->prevEnv;
        }

        $composer = $this->tmpDir . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($composer)) {
            unlink($composer);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    public function testKernelOverrideWins(): void
    {
        putenv('MITHRIL_JOB_KERNEL=Env\\Kernel');
        $this->writeComposer(['extra' => ['mithril' => ['job_kernel' => 'Composer\\Kernel']]]);

        $resolver = new JobApplicationResolver($this->tmpDir, 'Cli\\Kernel');

        $this->assertSame('Cli\\Kernel', $resolver->resolveKernelClass());
    }

    public function testEnvWinsOverComposer(): void
    {
        putenv('MITHRIL_JOB_KERNEL=Env\\JobKernel');
        $this->writeComposer(['extra' => ['mithril' => ['job_kernel' => 'Composer\\JobKernel']]]);

        $resolver = new JobApplicationResolver($this->tmpDir);

        $this->assertSame('Env\\JobKernel', $resolver->resolveKernelClass());
    }

    public function testComposerExtraJobKernel(): void
    {
        $this->writeComposer(['extra' => ['mithril' => ['job_kernel' => 'App\\CustomJobKernel']]]);

        $resolver = new JobApplicationResolver($this->tmpDir);

        $this->assertSame('App\\CustomJobKernel', $resolver->resolveKernelClass());
    }

    public function testFallbackIsAppJobKernel(): void
    {
        $resolver = new JobApplicationResolver($this->tmpDir);

        $this->assertSame('App\\JobKernel', $resolver->resolveKernelClass());
    }

    public function testAssertKernelLoadableRejectsMissingClass(): void
    {
        $resolver = new JobApplicationResolver($this->tmpDir, 'Definitely\\Missing\\JobKernel');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Job kernel class not found');
        $resolver->assertKernelLoadable();
    }

    public function testAssertKernelLoadableRejectsNonJobApplication(): void
    {
        $resolver = new JobApplicationResolver($this->tmpDir, \stdClass::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Job kernel must implement JobApplication');
        $resolver->assertKernelLoadable();
    }

    public function testAssertKernelLoadableAcceptsJobApplication(): void
    {
        $resolver = new JobApplicationResolver($this->tmpDir, StubJobKernel::class);
        $resolver->assertKernelLoadable();

        $this->assertSame(StubJobKernel::class, $resolver->resolveKernelClass());
    }

    /** @param array<string, mixed> $data */
    private function writeComposer(array $data): void
    {
        file_put_contents(
            $this->tmpDir . DIRECTORY_SEPARATOR . 'composer.json',
            json_encode($data, JSON_THROW_ON_ERROR)
        );
    }
}

final class StubJobKernel implements JobApplication
{
    public function boot(): void {}

    public function handle(JobEnvelope $job): JobResult
    {
        return JobResult::ack();
    }

    public function getContainer(): Container
    {
        return new Container();
    }
}
