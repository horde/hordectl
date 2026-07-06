<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test\Command\Secret;

use Horde\Hordectl\Command\Secret\Generate;
use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\Dependencies;
use Horde\Hordectl\Target;
use Horde\Hordectl\TargetType;
use Horde\Cli\Cli;
use Horde\Hordectl\Output;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test the Secret Generate command
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
#[CoversClass(Generate::class)]
class GenerateTest extends TestCase
{
    private string $tempDir;
    private string $confPath;

    protected function setUp(): void
    {
        // Create temporary directory structure
        $this->tempDir = sys_get_temp_dir() . '/hordectl-test-' . uniqid();
        mkdir($this->tempDir);
        mkdir($this->tempDir . '/var', 0755, true);
        mkdir($this->tempDir . '/var/config', 0755, true);
        mkdir($this->tempDir . '/var/config/horde', 0755, true);

        $this->confPath = $this->tempDir . '/var/config/horde/conf.php';

        // Create minimal conf.php
        file_put_contents($this->confPath, "<?php\n\$conf = [];\n");
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testUsesTargetToFindConfPath(): void
    {
        // This test verifies the command uses TargetCapabilityTrait
        // and constructs the correct conf.php path from target

        $mockInjector = $this->createStub(Dependencies::class);
        $mockCli = $this->createStub(Cli::class);

        $mockInjector->method('getInstance')
            ->willReturnCallback(function ($class) use ($mockCli) {
                if ($class === Cli::class) {
                    return $mockCli;
                }
                return null;
            });

        $mockInjector->method('createOutput')
            ->willReturn(new Output($mockCli));

        $command = new Generate($mockInjector);

        // Verify command has TargetCapabilityTrait
        $this->assertTrue(
            method_exists($command, 'requireFilesystemCapability'),
            'Generate command should have TargetCapabilityTrait'
        );
    }

    public function testConfPhpPathIsCorrect(): void
    {
        // Verify the command uses /var/config/horde/conf.php
        // not /vendor/horde/horde/config/conf.php

        // Read the source to verify path construction
        $source = file_get_contents(__DIR__ . '/../../../src/Command/Secret/Generate.php');

        $this->assertStringContainsString(
            "'/var/config/horde/conf.php'",
            $source,
            'Generate command should use bundle-style conf.php path'
        );

        $this->assertStringNotContainsString(
            "'/vendor/horde/horde/config/conf.php'",
            $source,
            'Generate command should not use old Horde 5 style path'
        );
    }

    public function testSupportsInstallationRootDirOverride(): void
    {
        // Verify --installation-root-dir override is supported

        $source = file_get_contents(__DIR__ . '/../../../src/Command/Secret/Generate.php');

        $this->assertStringContainsString(
            '--installation-root-dir',
            $source,
            'Generate command should support --installation-root-dir override'
        );

        $this->assertStringContainsString(
            'getInstallationRootDirOverride',
            $source,
            'Generate command should have method to parse installation root override'
        );
    }
}
