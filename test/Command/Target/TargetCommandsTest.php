<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test\Command\Target;

use Horde\Argv\Parser;
use Horde\Hordectl\Command\Target\Current;
use Horde\Hordectl\Command\Target\Delete;
use Horde\Hordectl\Command\Target\ListTargets;
use Horde\Hordectl\Command\Target\Rename;
use Horde\Hordectl\Command\Target\Show;
use Horde\Hordectl\Command\Target\UseTarget;
use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\Dependencies;
use Horde\Hordectl\TargetResolver;
use Horde_Cli;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Current::class)]
#[CoversClass(ListTargets::class)]
#[CoversClass(UseTarget::class)]
#[CoversClass(Rename::class)]
#[CoversClass(Delete::class)]
#[CoversClass(Show::class)]
class TargetCommandsTest extends TestCase
{
    private string $testConfigPath;
    private ConfigManager $config;
    private $mockInjector;
    private $mockCli;
    private $mockParser;

    protected function setUp(): void
    {
        $this->testConfigPath = sys_get_temp_dir() . '/hordectl-test-' . uniqid() . '.php';
        $this->config = new ConfigManager($this->testConfigPath);

        // Create mocks
        $this->mockInjector = $this->createMock(Dependencies::class);
        $this->mockCli = $this->createMock(Horde_Cli::class);
        $this->mockParser = new Parser();

        // Setup mock injector to return dependencies
        $this->mockInjector->method('getInstance')
            ->willReturnCallback(function ($class) {
                if ($class === '\Horde_Cli' || $class === Horde_Cli::class) {
                    return $this->mockCli;
                }
                if ($class === Parser::class || $class === '\Horde\Argv\Parser') {
                    return $this->mockParser;
                }
                return null;
            });

        // Create test targets
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
    }

    public function testCurrentCommandHandlesCurrentSubcommand(): void
    {
        $command = new Current($this->mockInjector);

        $result = $command->handle(['current']);

        $this->assertTrue($result);
    }

    public function testCurrentCommandIgnoresOtherSubcommands(): void
    {
        $command = new Current($this->mockInjector);

        $result = $command->handle(['list']);

        $this->assertFalse($result);
    }

    public function testListCommandHandlesListSubcommand(): void
    {
        $command = new ListTargets($this->mockInjector);

        $result = $command->handle(['list']);

        $this->assertTrue($result);
    }

    public function testUseCommandSwitchesTarget(): void
    {
        $command = new UseTarget($this->mockInjector);

        $result = $command->handle(['use', 'test2'], $this->config);

        $this->assertTrue($result);

        // Verify target was switched
        $this->config->load();
        $this->assertSame('test2', $this->config->get('current-target'));
    }

    public function testRenameCommandRenamesTarget(): void
    {
        $command = new Rename($this->mockInjector);

        $result = $command->handle(['rename', 'test1', 'renamed'], $this->config);

        $this->assertTrue($result);

        // Verify target was renamed
        $this->config->load();
        $resolver = new TargetResolver();
        $this->assertFalse($resolver->targetExists($this->config, 'test1'));
        $this->assertTrue($resolver->targetExists($this->config, 'renamed'));
    }

    public function testDeleteCommandDeletesTarget(): void
    {
        $command = new Delete($this->mockInjector);

        $result = $command->handle(['delete', 'test2'], $this->config);

        $this->assertTrue($result);

        // Verify target was deleted
        $this->config->load();
        $resolver = new TargetResolver();
        $this->assertFalse($resolver->targetExists($this->config, 'test2'));
    }

    public function testShowCommandHandlesShowSubcommand(): void
    {
        $command = new Show($this->mockInjector);

        $result = $command->handle(['show', 'test1']);

        $this->assertTrue($result);
    }
}
