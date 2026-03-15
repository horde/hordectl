<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test;

use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\HostDetector;
use Horde\Hordectl\HordeInstallationFinder;
use Horde\Hordectl\HordeNotFoundException;
use Horde\Hordectl\TargetResolver;
use Horde\Hordectl\TargetType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HostDetector::class)]
class HostDetectorTest extends TestCase
{
    private string $testConfigPath;
    private ConfigManager $config;
    private TargetResolver $resolver;

    protected function setUp(): void
    {
        $this->testConfigPath = sys_get_temp_dir() . '/hordectl-test-' . uniqid() . '.php';
        $this->config = new ConfigManager($this->testConfigPath);
        $this->resolver = new TargetResolver();

        // Initialize with empty V2 config
        $this->config->set('current-target', null);
        $this->config->set('targets', []);
        $this->config->save();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testConfigPath)) {
            unlink($this->testConfigPath);
        }
    }

    public function testAutoDetectAndAddHostCreatesHostTargetWhenHordeFound(): void
    {
        // Create mock finder that returns a valid path
        $finder = $this->createMock(HordeInstallationFinder::class);
        $finder->method('find')
            ->willReturn('/path/to/horde/vendor/horde/horde');

        $detector = new HostDetector($finder, $this->config, $this->resolver);
        $detector->autoDetectAndAddHost();

        // Reload config
        $this->config->load();

        // Should have created host target
        $this->assertTrue($this->resolver->targetExists($this->config, 'host'));

        $host = $this->resolver->getTarget($this->config, 'host');
        $this->assertSame('host', $host->name);
        $this->assertSame(TargetType::Local, $host->type);
        $this->assertSame('/path/to/horde/vendor/horde/horde', $host->hordeBase);
        $this->assertSame('/path/to/horde', $host->hordeInstallDir);
        $this->assertTrue($host->autoDetected);
        $this->assertStringContainsString('auto-detected', $host->description);
    }

    public function testAutoDetectAndAddHostSetsCurrentTargetWhenNoOtherTargets(): void
    {
        $finder = $this->createMock(HordeInstallationFinder::class);
        $finder->method('find')
            ->willReturn('/path/to/horde/vendor/horde/horde');

        $detector = new HostDetector($finder, $this->config, $this->resolver);
        $detector->autoDetectAndAddHost();

        // Reload config
        $this->config->load();

        $this->assertSame('host', $this->config->get('current-target'));
    }

    public function testAutoDetectAndAddHostSetsCurrentTargetWhenNoCurrentTarget(): void
    {
        // Add another target first
        $this->config->set('targets', [
            'existing' => [
                'type' => 'remote',
                'endpoint' => 'https://example.com/horde',
            ],
        ]);
        $this->config->set('current-target', null);
        $this->config->save();

        $finder = $this->createMock(HordeInstallationFinder::class);
        $finder->method('find')
            ->willReturn('/path/to/horde/vendor/horde/horde');

        $detector = new HostDetector($finder, $this->config, $this->resolver);
        $detector->autoDetectAndAddHost();

        // Reload config
        $this->config->load();

        // Should set host as current when no current target existed
        $this->assertSame('host', $this->config->get('current-target'));
    }

    public function testAutoDetectAndAddHostDoesNotSetCurrentTargetWhenCurrentTargetExists(): void
    {
        // Add another target first
        $this->config->set('targets', [
            'existing' => [
                'type' => 'remote',
                'endpoint' => 'https://example.com/horde',
            ],
        ]);
        $this->config->set('current-target', 'existing');
        $this->config->save();

        $finder = $this->createMock(HordeInstallationFinder::class);
        $finder->method('find')
            ->willReturn('/path/to/horde/vendor/horde/horde');

        $detector = new HostDetector($finder, $this->config, $this->resolver);
        $detector->autoDetectAndAddHost();

        // Reload config
        $this->config->load();

        // Should NOT change current target
        $this->assertSame('existing', $this->config->get('current-target'));
    }

    public function testAutoDetectAndAddHostDoesNothingWhenHostAlreadyExists(): void
    {
        // Create existing host target
        $this->config->set('targets', [
            'host' => [
                'type' => 'local',
                'horde_base' => '/existing/path',
            ],
        ]);
        $this->config->save();

        $finder = $this->createMock(HordeInstallationFinder::class);
        $finder->expects($this->never())
            ->method('find');

        $detector = new HostDetector($finder, $this->config, $this->resolver);
        $detector->autoDetectAndAddHost();

        // Reload config
        $this->config->load();

        // Should not have changed existing host
        $host = $this->resolver->getTarget($this->config, 'host');
        $this->assertSame('/existing/path', $host->hordeBase);
    }

    public function testAutoDetectAndAddHostDoesNothingWhenHordeNotFound(): void
    {
        $finder = $this->createMock(HordeInstallationFinder::class);
        $finder->method('find')
            ->will($this->throwException(new HordeNotFoundException('Not found')));

        $detector = new HostDetector($finder, $this->config, $this->resolver);
        $detector->autoDetectAndAddHost();

        // Reload config
        $this->config->load();

        // Should not have created any targets
        $this->assertFalse($this->resolver->targetExists($this->config, 'host'));
    }
}
