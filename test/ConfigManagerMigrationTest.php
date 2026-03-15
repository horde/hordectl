<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test;

use Horde\Hordectl\ConfigManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigManager::class)]
class ConfigManagerMigrationTest extends TestCase
{
    private string $testConfigPath;

    protected function setUp(): void
    {
        $this->testConfigPath = sys_get_temp_dir() . '/hordectl-test-' . uniqid() . '.php';
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

    public function testIsV2FormatReturnsTrueForV2Config(): void
    {
        $v2Config = [
            'current-target' => 'default',
            'targets' => [
                'default' => [
                    'type' => 'local',
                    'horde_base' => '/path/to/horde',
                ],
            ],
        ];

        file_put_contents($this->testConfigPath, '<?php return ' . var_export($v2Config, true) . ';');

        $config = new ConfigManager($this->testConfigPath);

        $this->assertTrue($config->isV2Format());
    }

    public function testIsV2FormatReturnsFalseForV1Config(): void
    {
        $v1Config = [
            'HORDE_BASE' => '/path/to/horde',
            'HORDE_INSTALL_DIR' => '/path/to',
            'admin_api' => [
                'endpoint' => 'http://localhost/horde',
                'admin_secret' => '',
            ],
        ];

        file_put_contents($this->testConfigPath, '<?php return ' . var_export($v1Config, true) . ';');

        $config = new ConfigManager($this->testConfigPath);

        // Should auto-migrate, so isV2Format should be true after load
        $this->assertTrue($config->isV2Format());
    }

    public function testMigrateToV2MigratesLocalConfigWithoutApi(): void
    {
        $v1Config = [
            'HORDE_BASE' => '/path/to/horde',
            'HORDE_INSTALL_DIR' => '/path/to',
        ];

        file_put_contents($this->testConfigPath, '<?php return ' . var_export($v1Config, true) . ';');

        $config = new ConfigManager($this->testConfigPath);

        $this->assertTrue($config->isV2Format());
        $this->assertSame('default', $config->get('current-target'));

        $targets = $config->get('targets');
        $this->assertIsArray($targets);
        $this->assertArrayHasKey('default', $targets);
        $this->assertSame('local', $targets['default']['type']);
        $this->assertSame('/path/to/horde', $targets['default']['horde_base']);
        $this->assertSame('/path/to', $targets['default']['horde_install_dir']);
    }

    public function testMigrateToV2MigratesLocalConfigWithApi(): void
    {
        $v1Config = [
            'HORDE_BASE' => '/path/to/horde',
            'HORDE_INSTALL_DIR' => '/path/to',
            'admin_api' => [
                'endpoint' => 'http://localhost/horde',
                'admin_secret' => 'secret123',
            ],
        ];

        file_put_contents($this->testConfigPath, '<?php return ' . var_export($v1Config, true) . ';');

        $config = new ConfigManager($this->testConfigPath);

        $targets = $config->get('targets');
        $this->assertSame('local', $targets['default']['type']);
        $this->assertSame('/path/to/horde', $targets['default']['horde_base']);
        $this->assertSame('http://localhost/horde', $targets['default']['endpoint']);
        $this->assertSame('secret123', $targets['default']['admin_secret']);
    }

    public function testMigrateToV2MigratesRemoteConfig(): void
    {
        $v1Config = [
            'admin_api' => [
                'endpoint' => 'https://example.com/horde',
                'admin_secret' => 'secret123',
            ],
        ];

        file_put_contents($this->testConfigPath, '<?php return ' . var_export($v1Config, true) . ';');

        $config = new ConfigManager($this->testConfigPath);

        $targets = $config->get('targets');
        $this->assertSame('remote', $targets['default']['type']);
        $this->assertSame('https://example.com/horde', $targets['default']['endpoint']);
        $this->assertSame('secret123', $targets['default']['admin_secret']);
        $this->assertArrayNotHasKey('horde_base', $targets['default']);
    }

    public function testMigrateToV2CreatesBackupFile(): void
    {
        $v1Config = [
            'HORDE_BASE' => '/path/to/horde',
        ];

        file_put_contents($this->testConfigPath, '<?php return ' . var_export($v1Config, true) . ';');

        new ConfigManager($this->testConfigPath);

        $this->assertFileExists($this->testConfigPath . '.bak');

        // Backup should contain original V1 config
        $backupContent = require $this->testConfigPath . '.bak';
        $this->assertArrayHasKey('HORDE_BASE', $backupContent);
        $this->assertArrayNotHasKey('targets', $backupContent);
    }

    public function testMigrateToV2DoesNotMigrateWhenAlreadyV2(): void
    {
        $v2Config = [
            'current-target' => 'test',
            'targets' => [
                'test' => [
                    'type' => 'local',
                    'horde_base' => '/path/to/horde',
                ],
            ],
        ];

        file_put_contents($this->testConfigPath, '<?php return ' . var_export($v2Config, true) . ';');

        $config = new ConfigManager($this->testConfigPath);

        $result = $config->migrateToV2();

        $this->assertFalse($result); // Should return false (already V2)
        $this->assertFileDoesNotExist($this->testConfigPath . '.bak');
    }

    public function testNewConfigIsV2Format(): void
    {
        $config = new ConfigManager($this->testConfigPath);

        $config->set('current-target', 'test');
        $config->set('targets', [
            'test' => [
                'type' => 'local',
                'horde_base' => '/path/to/horde',
            ],
        ]);
        $config->save();

        // Reload and verify
        $config2 = new ConfigManager($this->testConfigPath);

        $this->assertTrue($config2->isV2Format());
        $this->assertSame('test', $config2->get('current-target'));
    }

    public function testSaveCreatesDirectoryIfNotExists(): void
    {
        $tempDir = sys_get_temp_dir() . '/hordectl-test-dir-' . uniqid();
        $configPath = $tempDir . '/config.php';

        $config = new ConfigManager($configPath);
        $config->set('current-target', 'test');
        $config->set('targets', ['test' => ['type' => 'local']]);

        $result = $config->save();

        $this->assertTrue($result);
        $this->assertFileExists($configPath);
        $this->assertDirectoryExists($tempDir);

        // Cleanup
        unlink($configPath);
        rmdir($tempDir);
    }

    public function testSaveWritesV2FormatComment(): void
    {
        $config = new ConfigManager($this->testConfigPath);
        $config->set('current-target', 'test');
        $config->set('targets', ['test' => ['type' => 'local']]);
        $config->save();

        $content = file_get_contents($this->testConfigPath);

        $this->assertStringContainsString('Multi-Target Format', $content);
        $this->assertStringContainsString('current-target:', $content);
        $this->assertStringContainsString('targets:', $content);
    }
}
