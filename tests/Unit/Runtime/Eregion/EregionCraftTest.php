<?php

declare(strict_types=1);

namespace Erebor\Mithril\Tests\Unit\Runtime\Eregion;

use Erebor\Mithril\Runtime\Eregion\ApplicationResolver;
use Erebor\Mithril\Runtime\Eregion\EregionCraft;
use Erebor\Mithril\Runtime\Eregion\Manifest;
use PHPUnit\Framework\TestCase;

final class EregionCraftTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mithril-craft-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testCraftCreatesYamlAndManifest(): void
    {
        $actions = (new EregionCraft(new ApplicationResolver($this->dir)))->craft();

        $paths = array_column($actions, 'path');
        $this->assertContains($this->dir . DIRECTORY_SEPARATOR . 'eregion.yaml', $paths);
        $this->assertFileExists($this->dir . '/eregion.yaml');
        $this->assertFileExists($this->dir . '/var/runtime/eregion.json');

        $manifest = Manifest::fromFile($this->dir . '/var/runtime/eregion.json');
        $this->assertSame('App\\Kernel', $manifest->application);
        $this->assertSame($this->dir, $manifest->workingDirectory);
        $this->assertStringEndsWith('vendor' . DIRECTORY_SEPARATOR . 'autoload.php', $manifest->autoload);
    }

    public function testCraftSkipsExistingWithoutForce(): void
    {
        $craft = new EregionCraft(new ApplicationResolver($this->dir));
        $craft->craft();

        file_put_contents($this->dir . '/eregion.yaml', "# custom\n");
        $actions = $craft->craft(force: false);

        $byPath = [];
        foreach ($actions as $action) {
            $byPath[$action['path']] = $action['action'];
        }

        $this->assertSame('skipped', $byPath[$this->dir . DIRECTORY_SEPARATOR . 'eregion.yaml']);
        $this->assertSame("# custom\n", file_get_contents($this->dir . '/eregion.yaml'));
    }

    public function testCraftForceOverwrites(): void
    {
        $craft = new EregionCraft(new ApplicationResolver($this->dir));
        $craft->craft();
        file_put_contents($this->dir . '/eregion.yaml', "# custom\n");

        $actions = $craft->craft(force: true);
        $byPath = [];
        foreach ($actions as $action) {
            $byPath[$action['path']] = $action['action'];
        }

        $this->assertSame('overwritten', $byPath[$this->dir . DIRECTORY_SEPARATOR . 'eregion.yaml']);
        $this->assertStringContainsString('server:', (string) file_get_contents($this->dir . '/eregion.yaml'));
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
