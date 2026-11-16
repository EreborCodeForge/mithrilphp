<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Runtime\Eregion;

use Erebor\Mithril\Runtime\Eregion\EregionBinaryResolver;
use Erebor\Mithril\Runtime\Eregion\EregionBinaryVersionInspector;
use Erebor\Mithril\Runtime\Eregion\EregionInstaller;
use Erebor\Mithril\Runtime\Eregion\EregionRelease;
use Erebor\Mithril\Runtime\Eregion\Exceptions\EregionBinaryException;
use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;
use PHPUnit\Framework\TestCase;

final class EregionBinaryContractTest extends TestCase
{
    private string $dir;

    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eco01-' . uniqid('', true);
        mkdir($this->dir);
        $this->remember('EREGION_BINARY');
        $this->remember('EREGION_VERSION');
        $this->remember('PATH');
        putenv('EREGION_BINARY');
        putenv('EREGION_VERSION');
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        $this->removeTree($this->dir);
    }

    public function testEco01T01LocalBinaryWinsOverPath(): void
    {
        $pathDir = $this->dir . DIRECTORY_SEPARATOR . 'global';
        mkdir($pathDir);
        $global = $pathDir . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'eregion.exe' : 'eregion');
        file_put_contents($global, 'global');
        $local = (new EregionBinaryResolver())->localInstallPath($this->dir);
        $this->placeFixture($local, 'ok');
        putenv('PATH=' . $pathDir);

        $located = (new EregionBinaryResolver())->locate($this->dir);

        $this->assertNotNull($located);
        $this->assertSame($local, $located['path']);
        $this->assertSame('project', $located['origin']);
    }

    public function testEco01T02InvalidExplicitBinaryDoesNotFallBack(): void
    {
        $local = (new EregionBinaryResolver())->localInstallPath($this->dir);
        $this->placeFixture($local, 'ok');
        $missing = $this->dir . DIRECTORY_SEPARATOR . 'missing-eregion';
        putenv('EREGION_BINARY=' . $missing);

        try {
            (new EregionBinaryResolver())->resolve($this->dir);
            $this->fail('invalid EREGION_BINARY must not fall back');
        } catch (EregionBinaryException $e) {
            $this->assertStringContainsString('EREGION_BINARY', $e->getMessage());
            $this->assertStringNotContainsString($local, $e->getMessage());
        }
    }

    public function testEco01T03PinMismatchIsExplicit(): void
    {
        $binary = $this->placeFixture($this->dir . DIRECTORY_SEPARATOR . 'eregion-old' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''), 'old');

        $this->expectException(EregionBinaryException::class);
        $this->expectExceptionMessage('version mismatch');
        (new EregionBinaryVersionInspector())->assertCompatible($binary, 'v0.4.2');
    }

    public function testEco01T04ProtocolMismatchIsExplicit(): void
    {
        $binary = $this->placeFixture($this->dir . DIRECTORY_SEPARATOR . 'eregion-proto' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''), 'badproto');

        $this->expectException(EregionBinaryException::class);
        $this->expectExceptionMessage('protocol mismatch');
        (new EregionBinaryVersionInspector())->inspect($binary);
    }

    public function testEco01T05TimeoutExitAndMalformedAreControlled(): void
    {
        $bad = $this->placeFixture($this->dir . DIRECTORY_SEPARATOR . 'eregion-exit' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''), 'badexit');
        try {
            (new EregionBinaryVersionInspector())->inspect($bad);
            $this->fail('nonzero exit must fail');
        } catch (EregionBinaryException $e) {
            $this->assertStringContainsString('exited', $e->getMessage());
        }

        $malformed = $this->placeFixture($this->dir . DIRECTORY_SEPARATOR . 'eregion-bad' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''), 'malformed');
        try {
            (new EregionBinaryVersionInspector())->inspect($malformed);
            $this->fail('malformed stdout must fail');
        } catch (EregionBinaryException $e) {
            $this->assertStringContainsString('malformed', $e->getMessage());
        }

        $hang = $this->placeFixture($this->dir . DIRECTORY_SEPARATOR . 'eregion-hang' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''), 'hang');
        $started = microtime(true);
        try {
            (new EregionBinaryVersionInspector(0.4))->inspect($hang);
            $this->fail('timeout must fail');
        } catch (EregionBinaryException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
            $this->assertLessThan(3.0, microtime(true) - $started);
        }
    }

    public function testEco01T06CompatibleBinaryExistsWithoutDownload(): void
    {
        $resolver = new EregionBinaryResolver();
        $local = $resolver->localInstallPath($this->dir);
        $this->placeFixture($local, 'ok');
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
            'extra' => ['mithril' => ['eregion' => 'v0.4.2']],
        ], JSON_THROW_ON_ERROR));

        $downloader = static function (): string {
            throw new ProtocolException('network must not be used');
        };

        $result = (new EregionInstaller($resolver, $downloader))->install($this->dir);

        $this->assertSame('exists', $result['action']);
        $this->assertSame($local, $result['path']);
        $this->assertSame('v0.4.2', $result['version']);
    }

    public function testEco01T07IncompatibleBinaryIsNotAFalseSuccess(): void
    {
        $resolver = new EregionBinaryResolver();
        $local = $resolver->localInstallPath($this->dir);
        $this->placeFixture($local, 'old');
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'composer.json', json_encode([
            'extra' => ['mithril' => ['eregion' => 'v0.4.2']],
        ], JSON_THROW_ON_ERROR));

        $downloader = static function (): string {
            throw new ProtocolException('network must not be used');
        };

        try {
            (new EregionInstaller($resolver, $downloader))->install($this->dir);
            $this->fail('incompatible binary must not report success');
        } catch (ProtocolException $e) {
            $this->assertStringContainsString('version mismatch', $e->getMessage());
            $this->assertStringContainsString('--force', $e->getMessage());
        }
    }

    public function testVersionOutputMayAppendTheGoLine(): void
    {
        $binary = $this->placeFixture($this->dir . DIRECTORY_SEPARATOR . 'eregion-go' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''), 'withgo');

        $info = (new EregionBinaryVersionInspector())->inspect($binary);

        $this->assertSame('0.4.2', $info['version']);
        $this->assertSame('eregion/1', $info['protocol']);
    }

    public function testUnexpectedThirdVersionLineIsMalformed(): void
    {
        $binary = $this->placeFixture($this->dir . DIRECTORY_SEPARATOR . 'eregion-extra' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''), 'extra');

        $this->expectException(EregionBinaryException::class);
        $this->expectExceptionMessage('malformed');
        (new EregionBinaryVersionInspector())->inspect($binary);
    }

    public function testVersionCoreStripsOnlyThePrefix(): void
    {
        $this->assertSame(0, EregionRelease::compareVersions('v0.4.2', '0.4.2'));
        $this->assertSame('0.4.2', EregionRelease::versionCore('v0.4.2'));
    }

    private function placeFixture(string $dest, string $mode): string
    {
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            $this->fail('unable to create ' . $dir);
        }
        if (!copy(self::fixtureBinary(), $dest)) {
            $this->fail('unable to copy fixture to ' . $dest);
        }
        file_put_contents($dest . '.mode', $mode);

        return $dest;
    }

    private static function fixtureBinary(): string
    {
        $dir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'eco01fixture';
        $out = $dir . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'fixture.exe' : 'fixture');
        if (!is_file($out)) {
            $src = $dir . DIRECTORY_SEPARATOR . 'main.go';
            $command = 'go build -o ' . escapeshellarg($out) . ' ' . escapeshellarg($src);
            exec($command, $output, $code);
            if ($code !== 0 || !is_file($out)) {
                throw new \RuntimeException('go build failed: ' . implode("\n", $output));
            }
        }

        return $out;
    }

    private function remember(string $name): void
    {
        $value = getenv($name);
        $this->env[$name] = $value === false ? false : $value;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
