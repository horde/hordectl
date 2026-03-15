<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test;

use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\Exception\NoCurrentTargetException;
use Horde\Hordectl\Exception\TargetNotFoundException;
use Horde\Hordectl\Target;
use Horde\Hordectl\TargetResolver;
use Horde\Hordectl\TargetType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TargetResolver::class)]
class TargetResolverTest extends TestCase
{
    private string $testConfigPath;
    private ConfigManager $config;
    private TargetResolver $resolver;

    protected function setUp(): void
    {
        $this->testConfigPath = sys_get_temp_dir() . '/hordectl-test-' . uniqid() . '.php';
        $this->config = new ConfigManager($this->testConfigPath);
        $this->resolver = new TargetResolver();

        // Create initial V2 config
        $this->config->set('current-target', 'test1');
        $this->config->set('targets', [
            'test1' => [
                'type' => 'local',
                'horde_base' => '/path/to/horde',
                'horde_install_dir' => '/path/to',
            ],
            'test2' => [
                'type' => 'remote',
                'endpoint' => 'https://example.com/horde',
                'admin_secret' => 'secret',
            ],
        ]);
        $this->config->save();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testConfigPath)) {
            unlink($this->testConfigPath);
        }
        $backupPath = $this->testConfigPath . '.bak';
        if (file_exists($backupPath)) {
            unlink($backupPath);
        }
    }

    public function testGetCurrentTargetReturnsTarget(): void
    {
        $target = $this->resolver->getCurrentTarget($this->config);

        $this->assertSame('test1', $target->name);
        $this->assertSame(TargetType::Local, $target->type);
    }

    public function testGetCurrentTargetThrowsWhenNoCurrentTarget(): void
    {
        $this->config->set('current-target', null);

        $this->expectException(NoCurrentTargetException::class);
        $this->expectExceptionMessage("No current target set");

        $this->resolver->getCurrentTarget($this->config);
    }

    public function testGetCurrentTargetThrowsWhenCurrentTargetDoesNotExist(): void
    {
        $this->config->set('current-target', 'nonexistent');

        $this->expectException(TargetNotFoundException::class);
        $this->expectExceptionMessage("Target 'nonexistent' not found");

        $this->resolver->getCurrentTarget($this->config);
    }

    public function testGetTargetReturnsExistingTarget(): void
    {
        $target = $this->resolver->getTarget($this->config, 'test2');

        $this->assertSame('test2', $target->name);
        $this->assertSame(TargetType::Remote, $target->type);
        $this->assertSame('https://example.com/horde', $target->endpoint);
    }

    public function testGetTargetThrowsWhenTargetDoesNotExist(): void
    {
        $this->expectException(TargetNotFoundException::class);
        $this->expectExceptionMessage("Target 'nonexistent' not found");

        $this->resolver->getTarget($this->config, 'nonexistent');
    }

    public function testGetAllTargetsReturnsAllTargets(): void
    {
        $targets = $this->resolver->getAllTargets($this->config);

        $this->assertCount(2, $targets);
        $this->assertArrayHasKey('test1', $targets);
        $this->assertArrayHasKey('test2', $targets);
        $this->assertInstanceOf(Target::class, $targets['test1']);
        $this->assertInstanceOf(Target::class, $targets['test2']);
    }

    public function testGetAllTargetsReturnsEmptyArrayWhenNoTargets(): void
    {
        $this->config->set('targets', []);

        $targets = $this->resolver->getAllTargets($this->config);

        $this->assertIsArray($targets);
        $this->assertEmpty($targets);
    }

    public function testTargetExistsReturnsTrueWhenTargetExists(): void
    {
        $this->assertTrue($this->resolver->targetExists($this->config, 'test1'));
        $this->assertTrue($this->resolver->targetExists($this->config, 'test2'));
    }

    public function testTargetExistsReturnsFalseWhenTargetDoesNotExist(): void
    {
        $this->assertFalse($this->resolver->targetExists($this->config, 'nonexistent'));
    }

    public function testSaveTargetCreatesNewTarget(): void
    {
        $newTarget = new Target(
            name: 'test3',
            type: TargetType::Local,
            hordeBase: '/new/path/horde',
            hordeInstallDir: '/new/path',
            endpoint: null,
            adminSecret: null
        );

        $this->resolver->saveTarget($this->config, $newTarget);

        // Reload config from disk
        $this->config->load();

        $this->assertTrue($this->resolver->targetExists($this->config, 'test3'));
        $target = $this->resolver->getTarget($this->config, 'test3');
        $this->assertSame('/new/path/horde', $target->hordeBase);
    }

    public function testSaveTargetUpdatesExistingTarget(): void
    {
        $updatedTarget = new Target(
            name: 'test1',
            type: TargetType::Local,
            hordeBase: '/updated/path/horde',
            hordeInstallDir: '/updated/path',
            endpoint: null,
            adminSecret: null
        );

        $this->resolver->saveTarget($this->config, $updatedTarget);

        // Reload config from disk
        $this->config->load();

        $target = $this->resolver->getTarget($this->config, 'test1');
        $this->assertSame('/updated/path/horde', $target->hordeBase);
    }

    public function testDeleteTargetRemovesTarget(): void
    {
        $this->resolver->deleteTarget($this->config, 'test2');

        // Reload config from disk
        $this->config->load();

        $this->assertFalse($this->resolver->targetExists($this->config, 'test2'));
        $this->assertTrue($this->resolver->targetExists($this->config, 'test1'));
    }

    public function testDeleteTargetUnsetsCurrentTargetWhenDeletingCurrentTarget(): void
    {
        $this->config->set('current-target', 'test2');
        $this->config->save();

        $this->resolver->deleteTarget($this->config, 'test2');

        // Reload config from disk
        $this->config->load();

        $this->assertNull($this->config->get('current-target'));
    }

    public function testDeleteTargetDoesNotUnsetCurrentTargetWhenDeletingNonCurrentTarget(): void
    {
        $this->config->set('current-target', 'test1');
        $this->config->save();

        $this->resolver->deleteTarget($this->config, 'test2');

        // Reload config from disk
        $this->config->load();

        $this->assertSame('test1', $this->config->get('current-target'));
    }

    public function testSetCurrentTargetSetsCurrentTarget(): void
    {
        $this->resolver->setCurrentTarget($this->config, 'test2');

        // Reload config from disk
        $this->config->load();

        $this->assertSame('test2', $this->config->get('current-target'));
    }

    public function testSetCurrentTargetThrowsWhenTargetDoesNotExist(): void
    {
        $this->expectException(TargetNotFoundException::class);
        $this->expectExceptionMessage("Target 'nonexistent' does not exist");

        $this->resolver->setCurrentTarget($this->config, 'nonexistent');
    }

    public function testRenameTargetRenamesTarget(): void
    {
        $this->resolver->renameTarget($this->config, 'test1', 'renamed');

        // Reload config from disk
        $this->config->load();

        $this->assertFalse($this->resolver->targetExists($this->config, 'test1'));
        $this->assertTrue($this->resolver->targetExists($this->config, 'renamed'));

        $target = $this->resolver->getTarget($this->config, 'renamed');
        $this->assertSame('/path/to/horde', $target->hordeBase);
    }

    public function testRenameTargetUpdatesCurrentTargetWhenRenamingCurrentTarget(): void
    {
        $this->config->set('current-target', 'test1');
        $this->config->save();

        $this->resolver->renameTarget($this->config, 'test1', 'renamed');

        // Reload config from disk
        $this->config->load();

        $this->assertSame('renamed', $this->config->get('current-target'));
    }

    public function testRenameTargetDoesNotUpdateCurrentTargetWhenRenamingNonCurrentTarget(): void
    {
        $this->config->set('current-target', 'test1');
        $this->config->save();

        $this->resolver->renameTarget($this->config, 'test2', 'renamed');

        // Reload config from disk
        $this->config->load();

        $this->assertSame('test1', $this->config->get('current-target'));
    }

    public function testRenameTargetThrowsWhenOldTargetDoesNotExist(): void
    {
        $this->expectException(TargetNotFoundException::class);

        $this->resolver->renameTarget($this->config, 'nonexistent', 'renamed');
    }
}
