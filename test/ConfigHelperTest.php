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
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ConfigHelper tests
 *
 * ConfigHelper takes ($app, $installDir) and looks up
 *   $installDir/var/config/$app/conf.php
 * That layout is what horde-installer-plugin lays down (see
 * bundle/var/config/) so the tests mirror it exactly.
 *
 * ConfigHelper's constructor now requires the config directory and the
 * conf.php file to both exist — hordectl activate is the tool that
 * seeds them. Tests that used to exercise "create helper against a
 * nonexistent install" were removed; that state is no longer reachable
 * without going through activate first.
 */
#[CoversClass(ConfigHelper::class)]
class ConfigHelperTest extends TestCase
{
    private string $installDir;
    private string $configDir;
    private string $configFile;

    protected function setUp(): void
    {
        $this->installDir = sys_get_temp_dir() . '/hordectl-test-' . uniqid();
        $this->configDir = $this->installDir . '/var/config/horde';
        $this->configFile = $this->configDir . '/conf.php';

        mkdir($this->configDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ([
            $this->configFile,
            $this->configDir . '/conf.bak.php',
        ] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        // Prune the tree bottom-up. rmdir only removes empty dirs, so
        // stragglers (unexpected files) fail loudly rather than mask.
        @rmdir($this->configDir);
        @rmdir($this->installDir . '/var/config');
        @rmdir($this->installDir . '/var');
        @rmdir($this->installDir);
    }

    /**
     * Seed a Horde-shaped conf.php with configurable $conf contents.
     *
     * Wraps the payload in the CONFIG START/END markers plus scraps of
     * pre-header and post-footer content — the parts ConfigHelper is
     * supposed to preserve verbatim across edits.
     */
    private function seedConfigFile(array $conf = []): void
    {
        $defaults = [
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

        $conf = array_merge_recursive($defaults, $conf);

        $content = "<?php\n"
            . "// Pre-header content\n"
            . "\$default_value = 'preserved';\n\n"
            . "/* CONFIG START. DO NOT CHANGE ANYTHING IN OR AFTER THIS LINE. */\n"
            . "\$conf = " . var_export($conf, true) . ";\n"
            . "/* CONFIG END. DO NOT CHANGE ANYTHING IN OR BEFORE THIS LINE. */\n\n"
            . "// Post-footer content\n"
            . "\$override_value = 'also preserved';\n";

        file_put_contents($this->configFile, $content);
    }

    public function testConstructorWithExistingConfig(): void
    {
        $this->seedConfigFile();

        $helper = new ConfigHelper('horde', $this->installDir);

        $this->assertTrue($helper->fileExists());
        $this->assertSame($this->configFile, $helper->getConfigFile());
        $this->assertSame($this->configDir, $helper->getConfigDir());
    }

    public function testConstructorThrowsWhenConfDirMissing(): void
    {
        // Remove the seeded directory so the constructor path fails.
        rmdir($this->configDir);
        rmdir($this->installDir . '/var/config');
        rmdir($this->installDir . '/var');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configuration directory does not exist');

        new ConfigHelper('horde', $this->installDir);
    }

    public function testConstructorThrowsWhenConfFileMissing(): void
    {
        // Directory exists (from setUp) but no conf.php was seeded.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configuration file not found');

        new ConfigHelper('horde', $this->installDir);
    }

    public function testConstructorWithoutInstallDir(): void
    {
        $oldEnv = getenv('HORDE_INSTALL_DIR');
        putenv('HORDE_INSTALL_DIR=');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Cannot determine Horde installation directory');

            new ConfigHelper('horde');
        } finally {
            if ($oldEnv !== false) {
                putenv("HORDE_INSTALL_DIR={$oldEnv}");
            }
        }
    }

    public function testGetValue(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $this->assertSame('mysql', $helper->getValue('sql.phptype'));
        $this->assertSame('localhost', $helper->getValue('sql.hostspec'));
        $this->assertSame('sql', $helper->getValue('auth.driver'));
    }

    public function testGetValueNested(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $sql = $helper->getValue('sql');
        $this->assertIsArray($sql);
        $this->assertSame('mysql', $sql['phptype']);
    }

    public function testGetValueNonExistent(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $this->assertNull($helper->getValue('nonexistent'));
        $this->assertNull($helper->getValue('sql.nonexistent'));
        $this->assertNull($helper->getValue('sql.nested.deep.nonexistent'));
    }

    public function testSetValue(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $helper->setValue('sql.phptype', 'pgsql');
        $this->assertSame('pgsql', $helper->getValue('sql.phptype'));

        $helper->setValue('sql.port', 5432);
        $this->assertSame(5432, $helper->getValue('sql.port'));
    }

    public function testSetValueCreatesIntermediateArrays(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $helper->setValue('new.nested.deep.value', 'test');
        $this->assertSame('test', $helper->getValue('new.nested.deep.value'));

        $this->assertIsArray($helper->getValue('new'));
        $this->assertIsArray($helper->getValue('new.nested'));
        $this->assertIsArray($helper->getValue('new.nested.deep'));
    }

    public function testSetValueEmptyPath(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration path cannot be empty');

        $helper->setValue('', 'value');
    }

    public function testUnsetValue(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $this->assertTrue($helper->hasValue('sql.password'));
        $this->assertTrue($helper->unsetValue('sql.password'));
        $this->assertFalse($helper->hasValue('sql.password'));
    }

    public function testUnsetValueNonExistent(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $this->assertFalse($helper->unsetValue('nonexistent'));
        $this->assertFalse($helper->unsetValue('sql.nonexistent'));
    }

    public function testHasValue(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $this->assertTrue($helper->hasValue('sql.phptype'));
        $this->assertTrue($helper->hasValue('sql'));
        $this->assertFalse($helper->hasValue('nonexistent'));
        $this->assertFalse($helper->hasValue('sql.nonexistent'));
    }

    public function testGetAllAndSetAll(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

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
        $this->assertSame($newConf, $helper->getAll());
        $this->assertSame('new', $helper->getValue('test.value'));
    }

    public function testSave(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $helper->setValue('sql.phptype', 'pgsql');
        $helper->setValue('sql.port', 5432);

        $this->assertTrue($helper->save());
        $this->assertFileExists($this->configFile);
        $this->assertTrue($helper->backupExists());
        $this->assertFileExists($helper->getBackupFile());

        $helper2 = new ConfigHelper('horde', $this->installDir);
        $this->assertSame('pgsql', $helper2->getValue('sql.phptype'));
        $this->assertSame(5432, $helper2->getValue('sql.port'));
    }

    public function testSavePreservesProtectedSections(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $helper->setValue('sql.phptype', 'pgsql');
        $helper->save();

        $content = file_get_contents($this->configFile);

        $this->assertStringContainsString('CONFIG START', $content);
        $this->assertStringContainsString('CONFIG END', $content);
        $this->assertStringContainsString('$default_value', $content);
        $this->assertStringContainsString('$override_value', $content);
    }

    public function testRestoreFromBackup(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $original = $helper->getValue('sql.phptype');

        $helper->setValue('sql.phptype', 'pgsql');
        $helper->save();

        $this->assertSame('pgsql', $helper->getValue('sql.phptype'));

        $this->assertTrue($helper->restoreFromBackup());
        $this->assertSame($original, $helper->getValue('sql.phptype'));
    }

    public function testFormatForDisplay(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $display = $helper->formatForDisplay();

        $this->assertStringContainsString('sql:', $display);
        $this->assertStringContainsString('phptype:', $display);
        $this->assertStringContainsString('password: ********', $display);
        $this->assertStringNotContainsString('secret', $display);
        $this->assertStringContainsString('mysql', $display);
        $this->assertStringContainsString('localhost', $display);
    }

    public function testFormatForDisplayWithoutMasking(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $display = $helper->formatForDisplay(maskSensitive: false);

        $this->assertStringContainsString('password: secret', $display);
    }

    public function testBackupMethods(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $this->assertFalse($helper->backupExists());

        $helper->save();
        $this->assertTrue($helper->backupExists());
        $this->assertSame(
            $this->configDir . '/conf.bak.php',
            $helper->getBackupFile()
        );
    }

    public function testSaveToNonWritableDirectory(): void
    {
        $this->seedConfigFile();

        // r-x for owner: constructor can still stat the file, but save()
        // can't write to the directory. 0o444 would also strip the exec
        // bit and break the file_exists() probe in the constructor.
        chmod($this->configDir, 0o555);

        try {
            $helper = new ConfigHelper('horde', $this->installDir);
            $helper->setValue('sql.phptype', 'pgsql');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Config directory is not writable');

            $helper->save();
        } finally {
            chmod($this->configDir, 0o755);
        }
    }

    public function testMultipleValues(): void
    {
        $this->seedConfigFile();
        $helper = new ConfigHelper('horde', $this->installDir);

        $helper->setValue('sql.phptype', 'pgsql');
        $helper->setValue('sql.hostspec', 'postgres.example.com');
        $helper->setValue('sql.port', 5432);
        $helper->setValue('sql.username', 'admin');
        $helper->setValue('sql.database', 'production');

        $this->assertSame('pgsql', $helper->getValue('sql.phptype'));
        $this->assertSame('postgres.example.com', $helper->getValue('sql.hostspec'));
        $this->assertSame(5432, $helper->getValue('sql.port'));
        $this->assertSame('admin', $helper->getValue('sql.username'));
        $this->assertSame('production', $helper->getValue('sql.database'));

        $helper->save();
        $helper2 = new ConfigHelper('horde', $this->installDir);

        $this->assertSame('pgsql', $helper2->getValue('sql.phptype'));
        $this->assertSame('postgres.example.com', $helper2->getValue('sql.hostspec'));
        $this->assertSame(5432, $helper2->getValue('sql.port'));
        $this->assertSame('admin', $helper2->getValue('sql.username'));
        $this->assertSame('production', $helper2->getValue('sql.database'));
    }
}
