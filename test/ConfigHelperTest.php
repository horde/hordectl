<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Test;

use Horde\Hordectl\ConfigHelper;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use InvalidArgumentException;

#[CoversClass(ConfigHelper::class)]
class ConfigHelperTest extends TestCase
{
    private string $testDir;
    private string $testConfigFile;

    protected function setUp(): void
    {
        // Create temporary directory for test config files
        $this->testDir = sys_get_temp_dir() . '/hordectl-test-' . uniqid();
        mkdir($this->testDir);
        mkdir($this->testDir . '/config');
        $this->testConfigFile = $this->testDir . '/config/conf.php';
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (file_exists($this->testConfigFile)) {
            unlink($this->testConfigFile);
        }
        if (file_exists($this->testDir . '/config/conf.bak.php')) {
            unlink($this->testDir . '/config/conf.bak.php');
        }
        if (is_dir($this->testDir . '/config')) {
            rmdir($this->testDir . '/config');
        }
        if (is_dir($this->testDir)) {
            rmdir($this->testDir);
        }
    }

    /**
     * Create a test config file with protected sections
     */
    private function createTestConfigFile(array $conf = []): void
    {
        $defaultConf = [
            'sql' => [
                'phptype' => 'mysql',
                'hostspec' => 'localhost',
                'username' => 'horde',
                'password' => 'secret',
                'database' => 'horde',
            ],
            'auth' => [
                'driver' => 'sql',
            ],
        ];

        $conf = array_merge_recursive($defaultConf, $conf);

        $content = "<?php\n";
        $content .= "// Pre-header content\n";
        $content .= "\$default_value = 'preserved';\n\n";
        $content .= "/* CONFIG START. DO NOT CHANGE ANYTHING IN OR AFTER THIS LINE. */\n";
        $content .= "\$conf = " . var_export($conf, true) . ";\n";
        $content .= "/* CONFIG END. DO NOT CHANGE ANYTHING IN OR BEFORE THIS LINE. */\n\n";
        $content .= "// Post-footer content\n";
        $content .= "\$override_value = 'also preserved';\n";

        file_put_contents($this->testConfigFile, $content);
    }

    public function testConstructorWithExistingConfig(): void
    {
        $this->createTestConfigFile();

        $helper = new ConfigHelper('horde', $this->testDir);

        $this->assertTrue($helper->fileExists());
        $this->assertEquals($this->testConfigFile, $helper->getConfigFile());
        $this->assertEquals($this->testDir . '/config', $helper->getConfigDir());
    }

    public function testConstructorWithoutExistingConfig(): void
    {
        $helper = new ConfigHelper('horde', $this->testDir);

        $this->assertFalse($helper->fileExists());
        $this->assertEquals($this->testConfigFile, $helper->getConfigFile());
    }

    public function testConstructorWithoutInstallDir(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot determine Horde installation directory');

        // Ensure HORDE_INSTALL_DIR is not set
        $oldEnv = getenv('HORDE_INSTALL_DIR');
        putenv('HORDE_INSTALL_DIR=');

        try {
            new ConfigHelper('horde');
        } finally {
            // Restore environment
            if ($oldEnv !== false) {
                putenv("HORDE_INSTALL_DIR={$oldEnv}");
            }
        }
    }

    public function testGetValue(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        $this->assertEquals('mysql', $helper->getValue('sql.phptype'));
        $this->assertEquals('localhost', $helper->getValue('sql.hostspec'));
        $this->assertEquals('sql', $helper->getValue('auth.driver'));
    }

    public function testGetValueNested(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        // Get entire sql array
        $sql = $helper->getValue('sql');
        $this->assertIsArray($sql);
        $this->assertEquals('mysql', $sql['phptype']);
    }

    public function testGetValueNonExistent(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        $this->assertNull($helper->getValue('nonexistent'));
        $this->assertNull($helper->getValue('sql.nonexistent'));
        $this->assertNull($helper->getValue('sql.nested.deep.nonexistent'));
    }

    public function testSetValue(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        $helper->setValue('sql.phptype', 'pgsql');
        $this->assertEquals('pgsql', $helper->getValue('sql.phptype'));

        $helper->setValue('sql.port', 5432);
        $this->assertEquals(5432, $helper->getValue('sql.port'));
    }

    public function testSetValueCreatesIntermediateArrays(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        $helper->setValue('new.nested.deep.value', 'test');
        $this->assertEquals('test', $helper->getValue('new.nested.deep.value'));

        // Verify intermediate arrays were created
        $this->assertIsArray($helper->getValue('new'));
        $this->assertIsArray($helper->getValue('new.nested'));
        $this->assertIsArray($helper->getValue('new.nested.deep'));
    }

    public function testSetValueEmptyPath(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration path cannot be empty');

        $helper->setValue('', 'value');
    }

    public function testUnsetValue(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        $this->assertTrue($helper->hasValue('sql.password'));
        $this->assertTrue($helper->unsetValue('sql.password'));
        $this->assertFalse($helper->hasValue('sql.password'));
    }

    public function testUnsetValueNonExistent(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        $this->assertFalse($helper->unsetValue('nonexistent'));
        $this->assertFalse($helper->unsetValue('sql.nonexistent'));
    }

    public function testHasValue(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        $this->assertTrue($helper->hasValue('sql.phptype'));
        $this->assertTrue($helper->hasValue('sql'));
        $this->assertFalse($helper->hasValue('nonexistent'));
        $this->assertFalse($helper->hasValue('sql.nonexistent'));
    }

    public function testGetAllAndSetAll(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        $all = $helper->getAll();
        $this->assertIsArray($all);
        $this->assertArrayHasKey('sql', $all);
        $this->assertArrayHasKey('auth', $all);

        $newConf = [
            'test' => [
                'value' => 'new',
            ],
        ];

        $helper->setAll($newConf);
        $this->assertEquals($newConf, $helper->getAll());
        $this->assertEquals('new', $helper->getValue('test.value'));
    }

    public function testSave(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        // Modify config
        $helper->setValue('sql.phptype', 'pgsql');
        $helper->setValue('sql.port', 5432);

        // Save
        $this->assertTrue($helper->save());

        // Verify file was written
        $this->assertFileExists($this->testConfigFile);

        // Verify backup was created
        $this->assertTrue($helper->backupExists());
        $this->assertFileExists($helper->getBackupFile());

        // Create new helper to read saved file
        $helper2 = new ConfigHelper('horde', $this->testDir);
        $this->assertEquals('pgsql', $helper2->getValue('sql.phptype'));
        $this->assertEquals(5432, $helper2->getValue('sql.port'));
    }

    public function testSavePreservesProtectedSections(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        // Modify and save
        $helper->setValue('sql.phptype', 'pgsql');
        $helper->save();

        // Read file content directly
        $content = file_get_contents($this->testConfigFile);

        // Verify markers are present
        $this->assertStringContainsString('CONFIG START', $content);
        $this->assertStringContainsString('CONFIG END', $content);

        // Verify pre-header content is preserved
        $this->assertStringContainsString('$default_value', $content);

        // Verify post-footer content is preserved
        $this->assertStringContainsString('$override_value', $content);
    }

    public function testSaveWithoutExistingFile(): void
    {
        $helper = new ConfigHelper('horde', $this->testDir);

        $this->assertFalse($helper->fileExists());

        // Set some values
        $helper->setValue('sql.phptype', 'mysql');
        $helper->setValue('sql.hostspec', 'localhost');

        // Save
        $this->assertTrue($helper->save());

        // Verify file was created
        $this->assertFileExists($this->testConfigFile);

        // No backup should exist (no previous file)
        $this->assertFileDoesNotExist($helper->getBackupFile());

        // Verify values
        $helper2 = new ConfigHelper('horde', $this->testDir);
        $this->assertEquals('mysql', $helper2->getValue('sql.phptype'));
    }

    public function testRestoreFromBackup(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        $originalValue = $helper->getValue('sql.phptype');

        // Modify and save (creates backup)
        $helper->setValue('sql.phptype', 'pgsql');
        $helper->save();

        $this->assertEquals('pgsql', $helper->getValue('sql.phptype'));

        // Restore from backup
        $this->assertTrue($helper->restoreFromBackup());
        $this->assertEquals($originalValue, $helper->getValue('sql.phptype'));
    }

    public function testRestoreFromBackupWithoutBackup(): void
    {
        $helper = new ConfigHelper('horde', $this->testDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Backup file does not exist');

        $helper->restoreFromBackup();
    }

    public function testFormatForDisplay(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        $display = $helper->formatForDisplay();

        // Should contain keys
        $this->assertStringContainsString('sql:', $display);
        $this->assertStringContainsString('phptype:', $display);

        // Should mask password
        $this->assertStringContainsString('password: ********', $display);
        $this->assertStringNotContainsString('secret', $display);

        // Should show non-sensitive values
        $this->assertStringContainsString('mysql', $display);
        $this->assertStringContainsString('localhost', $display);
    }

    public function testFormatForDisplayWithoutMasking(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        $display = $helper->formatForDisplay(maskSensitive: false);

        // Should show actual password
        $this->assertStringContainsString('password: secret', $display);
    }

    public function testBackupMethods(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        // Initially no backup
        $this->assertFalse($helper->backupExists());

        // Save creates backup
        $helper->save();
        $this->assertTrue($helper->backupExists());
        $this->assertEquals(
            $this->testDir . '/config/conf.bak.php',
            $helper->getBackupFile()
        );
    }

    public function testSaveToNonWritableDirectory(): void
    {
        $this->createTestConfigFile();

        // Make directory non-writable
        chmod($this->testDir . '/config', 0o444);

        try {
            $helper = new ConfigHelper('horde', $this->testDir);
            $helper->setValue('sql.phptype', 'pgsql');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Config directory is not writable');

            $helper->save();
        } finally {
            // Restore permissions for cleanup
            chmod($this->testDir . '/config', 0o755);
        }
    }

    public function testMultipleValues(): void
    {
        $this->createTestConfigFile();
        $helper = new ConfigHelper('horde', $this->testDir);

        // Set multiple values
        $helper->setValue('sql.phptype', 'pgsql');
        $helper->setValue('sql.hostspec', 'postgres.example.com');
        $helper->setValue('sql.port', 5432);
        $helper->setValue('sql.username', 'admin');
        $helper->setValue('sql.database', 'production');

        // Verify all values
        $this->assertEquals('pgsql', $helper->getValue('sql.phptype'));
        $this->assertEquals('postgres.example.com', $helper->getValue('sql.hostspec'));
        $this->assertEquals(5432, $helper->getValue('sql.port'));
        $this->assertEquals('admin', $helper->getValue('sql.username'));
        $this->assertEquals('production', $helper->getValue('sql.database'));

        // Save and reload
        $helper->save();
        $helper2 = new ConfigHelper('horde', $this->testDir);

        // Verify all values persisted
        $this->assertEquals('pgsql', $helper2->getValue('sql.phptype'));
        $this->assertEquals('postgres.example.com', $helper2->getValue('sql.hostspec'));
        $this->assertEquals(5432, $helper2->getValue('sql.port'));
        $this->assertEquals('admin', $helper2->getValue('sql.username'));
        $this->assertEquals('production', $helper2->getValue('sql.database'));
    }
}
