<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test;

use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\Environment;
use Horde\Hordectl\EnvTargetHandler;
use Horde\Hordectl\TargetResolver;
use Horde\Hordectl\TargetType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnvTargetHandler::class)]
class EnvTargetHandlerTest extends TestCase
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

    public function testCreateOrUpdateFromEnvCreatesTargetFromHordeBase(): void
    {
        $env = new Environment([
            'HORDE_BASE' => '/path/to/horde/vendor/horde/horde',
        ]);

        $handler = new EnvTargetHandler($env, $this->config, $this->resolver);
        $handler->createOrUpdateFromEnv();

        // Reload config
        $this->config->load();

        $this->assertTrue($this->resolver->targetExists($this->config, 'last_env'));

        $target = $this->resolver->getTarget($this->config, 'last_env');
        $this->assertSame('last_env', $target->name);
        $this->assertSame(TargetType::Local, $target->type);
        $this->assertSame('/path/to/horde/vendor/horde/horde', $target->hordeBase);
        $this->assertSame('/path/to/horde', $target->hordeInstallDir);
        $this->assertTrue($target->fromEnv);
        $this->assertStringContainsString('environment variables', $target->description);
    }

    public function testCreateOrUpdateFromEnvCreatesTargetFromHordeGitDir(): void
    {
        $env = new Environment([
            'HORDE_GIT_DIR' => '/path/to/git/horde',
        ]);

        $handler = new EnvTargetHandler($env, $this->config, $this->resolver);
        $handler->createOrUpdateFromEnv();

        // Reload config
        $this->config->load();

        $target = $this->resolver->getTarget($this->config, 'last_env');
        $this->assertSame('/path/to/git/horde/base', $target->hordeBase);
        // From /path/to/git/horde/base, remove /vendor/horde/horde = /path/to
        $this->assertSame('/path/to', $target->hordeInstallDir);
    }

    public function testCreateOrUpdateFromEnvPrefersHordeGitDirOverHordeBase(): void
    {
        $env = new Environment([
            'HORDE_BASE' => '/path/from/base',
            'HORDE_GIT_DIR' => '/path/from/git',
        ]);

        $handler = new EnvTargetHandler($env, $this->config, $this->resolver);
        $handler->createOrUpdateFromEnv();

        // Reload config
        $this->config->load();

        $target = $this->resolver->getTarget($this->config, 'last_env');
        // Should use HORDE_GIT_DIR
        $this->assertSame('/path/from/git/base', $target->hordeBase);
    }

    public function testCreateOrUpdateFromEnvSetsCurrentTargetWhenNoCurrentTarget(): void
    {
        $env = new Environment([
            'HORDE_BASE' => '/path/to/horde/vendor/horde/horde',
        ]);

        $handler = new EnvTargetHandler($env, $this->config, $this->resolver);
        $handler->createOrUpdateFromEnv();

        // Reload config
        $this->config->load();

        $this->assertSame('last_env', $this->config->get('current-target'));
    }

    public function testCreateOrUpdateFromEnvDoesNotSetCurrentTargetWhenCurrentTargetExists(): void
    {
        // Add existing target
        $this->config->set('targets', [
            'existing' => [
                'type' => 'remote',
                'endpoint' => 'https://example.com/horde',
            ],
        ]);
        $this->config->set('current-target', 'existing');
        $this->config->save();

        $env = new Environment([
            'HORDE_BASE' => '/path/to/horde/vendor/horde/horde',
        ]);

        $handler = new EnvTargetHandler($env, $this->config, $this->resolver);
        $handler->createOrUpdateFromEnv();

        // Reload config
        $this->config->load();

        // Should NOT change current target
        $this->assertSame('existing', $this->config->get('current-target'));
    }

    public function testCreateOrUpdateFromEnvUpdatesExistingLastEnvTarget(): void
    {
        // Create existing last_env target
        $this->config->set('targets', [
            'last_env' => [
                'type' => 'local',
                'horde_base' => '/old/path',
                'horde_install_dir' => '/old',
            ],
        ]);
        $this->config->save();

        $env = new Environment([
            'HORDE_BASE' => '/new/path/horde',
        ]);

        $handler = new EnvTargetHandler($env, $this->config, $this->resolver);
        $handler->createOrUpdateFromEnv();

        // Reload config
        $this->config->load();

        $target = $this->resolver->getTarget($this->config, 'last_env');
        $this->assertSame('/new/path/horde', $target->hordeBase);
    }

    public function testCreateOrUpdateFromEnvDoesNothingWhenNoEnvVarsSet(): void
    {
        $env = new Environment([]);

        $handler = new EnvTargetHandler($env, $this->config, $this->resolver);
        $handler->createOrUpdateFromEnv();

        // Reload config
        $this->config->load();

        $this->assertFalse($this->resolver->targetExists($this->config, 'last_env'));
    }
}
