<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test\Command;

use Horde\Hordectl\Command\Help;
use Horde\Hordectl\Dependencies;
use Horde_Cli;
use Horde\Argv\Parser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use stdClass;

/**
 * Test the Help command
 */
#[CoversClass(Help::class)]
#[AllowMockObjectsWithoutExpectations]
class HelpTest extends TestCase
{
    private $mockInjector;
    private $mockCli;
    private $mockParser;

    protected function setUp(): void
    {
        $this->mockInjector = $this->createMock(Dependencies::class);
        $this->mockCli = $this->createMock(Horde_Cli::class);
        $this->mockParser = $this->createMock(Parser::class);

        // Setup default mock behavior for basic constructor needs
        // Note: Tests can override this by calling method() again
        $this->mockInjector->method('getInstance')
            ->willReturnCallback(function ($class) {
                if ($class === '\Horde_Cli') {
                    return $this->mockCli;
                }
                if ($class === '\Horde_Argv_Parser' || $class === Parser::class) {
                    return $this->mockParser;
                }
                // For HordeRegistry and other classes, return null by default
                // Individual tests should set up their specific needs
                return null;
            });
    }

    public function testConstructorCreatesHelpInstance(): void
    {
        $help = new Help($this->mockInjector);
        $this->assertInstanceOf(Help::class, $help);
    }

    public function testConstructorGetsCliFromInjector(): void
    {
        $this->mockInjector->expects($this->atLeastOnce())
            ->method('getInstance')
            ->with($this->logicalOr(
                $this->equalTo('\Horde_Cli'),
                $this->equalTo(Parser::class)
            ));

        new Help($this->mockInjector);
    }

    public function testConstructorGetsParserFromInjector(): void
    {
        $this->mockInjector->expects($this->atLeastOnce())
            ->method('getInstance')
            ->with($this->logicalOr(
                $this->equalTo(Parser::class),
                $this->equalTo('\Horde_Cli')
            ));

        new Help($this->mockInjector);
    }

    public function testHandleReturnsFalseOnEmptyArgv(): void
    {
        $help = new Help($this->mockInjector);
        $result = $help->handle([]);
        $this->assertFalse($result);
    }

    public function testHandleReturnsFalseWhenNotHelpCommand(): void
    {
        $help = new Help($this->mockInjector);
        $result = $help->handle(['other']);
        $this->assertFalse($result);
    }

    public function testHandleWritesHelpWhenHelpCommand(): void
    {
        // Setup mock to return required dependencies for help command
        $this->mockInjector->method('getInstance')
            ->willReturnCallback(function ($class) {
                if ($class === '\Horde_Cli') {
                    return $this->mockCli;
                }
                if ($class === '\Horde_Argv_Parser' || $class === Parser::class) {
                    return $this->mockParser;
                }
                if ($class === 'HordeRegistry') {
                    $mockRegistry = new stdClass();
                    $mockRegistry->applications = [];
                    return $mockRegistry;
                }
                return null;
            });

        $this->mockInjector->method('findHordePath')
            ->willReturn('/tmp/horde');

        $this->mockInjector->method('getRegistryApplications')
            ->willReturn([]);

        $help = new Help($this->mockInjector);

        // Expect at least the "Help" message to be written
        $helpDisplayed = false;
        $this->mockCli->expects($this->atLeastOnce())
            ->method('writeln')
            ->willReturnCallback(function ($message) use (&$helpDisplayed) {
                if (stripos($message, 'help') !== false) {
                    $helpDisplayed = true;
                }
            });

        $result = $help->handle(['help']);
        $this->assertTrue($result);
        $this->assertTrue($helpDisplayed, "Expected 'help' message to be displayed");
    }

    public function testHelpCommandDisplaysHordePath(): void
    {
        $testPath = '/test/horde/path';

        $this->mockInjector->method('getInstance')
            ->willReturnCallback(function ($class) {
                if ($class === '\Horde_Cli') {
                    return $this->mockCli;
                }
                if ($class === '\Horde_Argv_Parser' || $class === Parser::class) {
                    return $this->mockParser;
                }
                if ($class === 'HordeRegistry') {
                    $mockRegistry = new stdClass();
                    $mockRegistry->applications = [];
                    return $mockRegistry;
                }
                return null;
            });

        $this->mockInjector->method('findHordePath')
            ->willReturn($testPath);

        $this->mockInjector->method('getRegistryApplications')
            ->willReturn([]);

        $help = new Help($this->mockInjector);

        // Expect the path to be displayed in at least one writeln call
        $pathDisplayed = false;
        $this->mockCli->expects($this->atLeastOnce())
            ->method('writeln')
            ->willReturnCallback(function ($message) use ($testPath, &$pathDisplayed) {
                if (str_contains($message, $testPath)) {
                    $pathDisplayed = true;
                }
            });

        $help->handle(['help']);
        $this->assertTrue($pathDisplayed, "Expected path '$testPath' to be displayed");
    }

    public function testHelpCommandListsApplications(): void
    {
        // Create a fresh mock for this test with custom registry
        $mockInjector = $this->createMock(Dependencies::class);
        $mockCli = $this->createMock(Horde_Cli::class);
        $mockParser = $this->createMock(Parser::class);

        $mockRegistry = new stdClass();
        $mockRegistry->applications = [
            'testapp' => ['status' => 'active'],
        ];

        $mockInjector->method('getInstance')
            ->willReturnCallback(function ($class) use ($mockCli, $mockParser, $mockRegistry) {
                if ($class === '\Horde_Cli') {
                    return $mockCli;
                }
                if ($class === '\Horde_Argv_Parser' || $class === Parser::class) {
                    return $mockParser;
                }
                if ($class === 'HordeRegistry') {
                    return $mockRegistry;
                }
                return null;
            });

        $mockInjector->method('findHordePath')
            ->willReturn('/tmp/horde');

        $mockInjector->method('getRegistryApplications')
            ->willReturn(['testapp']);

        $mockInjector->method('getApplicationResources')
            ->willReturn(null);

        $help = new Help($mockInjector);

        // Expect application to be listed
        $appDisplayed = false;
        $mockCli->expects($this->atLeastOnce())
            ->method('writeln')
            ->willReturnCallback(function ($message) use (&$appDisplayed) {
                if (str_contains($message, 'testapp')) {
                    $appDisplayed = true;
                }
            });

        $help->handle(['help']);
        $this->assertTrue($appDisplayed, "Expected 'testapp' to be displayed");
    }
}
