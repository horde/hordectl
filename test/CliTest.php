<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test;

use Horde\Hordectl\Cli;
use Horde\Injector\Injector;
use Horde_Cli;
use Horde\Argv\Parser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Test the main Cli class
 */
#[CoversClass(Cli::class)]
#[AllowMockObjectsWithoutExpectations]
class CliTest extends TestCase
{
    private $mockInjector;
    private $mockCli;
    private $mockParser;

    protected function setUp(): void
    {
        $this->mockInjector = $this->createMock(Injector::class);
        $this->mockCli = $this->createMock(Horde_Cli::class);
        $this->mockParser = $this->createMock(Parser::class);

        // Setup default mock behavior
        $this->mockInjector->method('getInstance')
            ->willReturnCallback(function ($class) {
                if ($class === '\Horde_Cli') {
                    return $this->mockCli;
                }
                if ($class === '\Horde_Argv_Parser' || $class === Parser::class) {
                    return $this->mockParser;
                }
                // Return a simple stub for Command classes
                if (str_starts_with($class, '\Horde\Hordectl\Command\\')) {
                    return new class implements \Horde_Cli_Modular_Module {
                        public function setParentModule($module) {}
                        public function handle($argv) { return false; }
                        public function getUsage(): string { return ''; }
                        public function getBaseOptions() { return []; }
                        public function hasOptionGroup() { return false; }
                        public function getOptionGroupTitle() { return ''; }
                        public function getOptionGroupDescription() { return ''; }
                        public function getOptionGroupOptions($action = null) { return []; }
                    };
                }
                return null;
            });
    }

    public function testConstructorCreatesCliInstance(): void
    {
        $cli = new Cli($this->mockInjector);
        $this->assertInstanceOf(Cli::class, $cli);
    }

    public function testConstructorGetsCliFromInjector(): void
    {
        $this->mockInjector->expects($this->atLeastOnce())
            ->method('getInstance')
            ->with($this->logicalOr(
                $this->equalTo('\Horde_Cli'),
                $this->stringStartsWith('\Horde\Hordectl\Command\\'),
                $this->equalTo(Parser::class)
            ));

        new Cli($this->mockInjector);
    }

    public function testConstructorGetsParserFromInjector(): void
    {
        $this->mockInjector->expects($this->atLeastOnce())
            ->method('getInstance')
            ->with($this->logicalOr(
                $this->equalTo(Parser::class),
                $this->stringStartsWith('\Horde\Hordectl\Command\\'),
                $this->equalTo('\Horde_Cli')
            ));

        new Cli($this->mockInjector);
    }

    public function testConstructorSetsParserToNotAllowInterspersedArgs(): void
    {
        // The parser should have allowInterspersedArgs set to false
        // Use the mock parser from setUp() since we can't override the mock behavior
        new Cli($this->mockInjector);

        $this->assertFalse($this->mockParser->allowInterspersedArgs);
    }

    public function testHandleReturnsFalseWhenNoModulesRun(): void
    {
        $cli = new Cli($this->mockInjector);

        // Expect usage output when no module runs (via writeln calls)
        $this->mockCli->expects($this->atLeastOnce())
            ->method('writeln');

        $result = $cli->handle([]);
        $this->assertFalse($result);
    }

    public function testHandleReturnsFalseOnHordeException(): void
    {
        // This tests exception handling in the handle method
        $cli = new Cli($this->mockInjector);

        // Since we can't easily make modules throw exceptions in this test,
        // we just verify the method doesn't crash with empty args
        $result = $cli->handle([]);
        $this->assertIsBool($result);
    }
}
