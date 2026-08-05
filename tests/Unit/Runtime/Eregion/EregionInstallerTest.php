<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Runtime\Eregion;

use Erebor\Mithril\Runtime\Eregion\EregionInstaller;
use Erebor\Mithril\Runtime\Eregion\EregionRelease;
use Erebor\Mithril\Runtime\Eregion\Exceptions\ProtocolException;
use PHPUnit\Framework\TestCase;

final class EregionInstallerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mithril-eregion-install-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testAssetNameWindowsAmd64(): void
    {
        $this->assertSame(
            'eregion-windows-amd64.exe',
            EregionRelease::assetName('Windows', 'AMD64')
        );
    }

    public function testAssetNameLinuxArm64(): void
    {
        $this->assertSame(
            'eregion-linux-arm64',
            EregionRelease::assetName('Linux', 'aarch64')
        );
    }

    public function testNormalizeVersion(): void
    {
        $this->assertSame('v0.1.0', EregionRelease::normalizeVersion('0.1.0'));
        $this->assertSame('v0.1.0', EregionRelease::normalizeVersion('v0.1.0'));
    }

    public function testDownloadUrl(): void
    {
        $url = EregionRelease::downloadUrl('EreborCodeForge/eregion', '0.1.0', 'checksums.txt');
        $this->assertSame(
            'https://github.com/EreborCodeForge/eregion/releases/download/v0.1.0/checksums.txt',
            $url
        );
    }

    public function testParseChecksums(): void
    {
        $raw = <<<'TXT'
23e1e3e6b046371f5ea7645b014ff14ee5727e829960f950ed88c3939972e53a  eregion-linux-amd64
ea43bc382ef47ed1e1688e9779c5bb0b9d4670aa683648f239560a44fb4cd7e3  eregion-windows-amd64.exe
TXT;

        $map = (new EregionInstaller())->parseChecksums($raw);
        $this->assertSame('ea43bc382ef47ed1e1688e9779c5bb0b9d4670aa683648f239560a44fb4cd7e3', $map['eregion-windows-amd64.exe']);
    }

    public function testInstallVerifiesChecksumAndWritesBinary(): void
    {
        $payload = "fake-eregion-binary\n";
        $hash = hash('sha256', $payload);
        $asset = EregionRelease::assetName();

        $downloader = static function (string $url) use ($payload, $hash, $asset): string {
            if (str_ends_with($url, 'checksums.txt')) {
                return "{$hash}  {$asset}\n";
            }
            if (str_ends_with($url, $asset)) {
                return $payload;
            }
            throw new ProtocolException('unexpected url ' . $url);
        };

        $installer = new EregionInstaller(downloader: $downloader);
        $result = $installer->install($this->dir, version: 'v0.1.0', force: true);

        $this->assertSame('installed', $result['action']);
        $this->assertSame($asset, $result['asset']);
        $this->assertFileExists($result['path']);
        $this->assertSame($payload, file_get_contents($result['path']));
    }

    public function testInstallRejectsChecksumMismatch(): void
    {
        $asset = EregionRelease::assetName();
        $downloader = static function (string $url) use ($asset): string {
            if (str_ends_with($url, 'checksums.txt')) {
                return '0000000000000000000000000000000000000000000000000000000000000000  ' . $asset . "\n";
            }

            return "payload\n";
        };

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('SHA-256 mismatch');
        (new EregionInstaller(downloader: $downloader))->install($this->dir, force: true);
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
                unlink($full);
            }
        }

        rmdir($path);
    }
}
