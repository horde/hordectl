<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test;

use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\Cli;
use Horde\Hordectl\Dependencies;
use Horde\Injector\TopLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test the root Cli module
 *
 * Cli is the root of hordectl's modular CLI tree. Its constructor asks the
 * injector for Horde\Cli\Cli and Horde\Argv\Parser, then auto-discovers
 * every Command/*.php file via HasModulesTrait::_initModules — instantiating
 * each one through the injector.
 *
 * Rather than mocking the whole discovery surface (which requires stubbing
 * getInstance() responses for every Command class the trait discovers), we
 * use a real Dependencies container. Real getInstance() calls resolve the
 * command classes for us; we only substitute Horde\Cli\Cli and Parser at
 * the injector level so we can observe writeln() and property state.
 */
#[CoversClass(Cli::class)]
class CliTest extends TestCase
{
    private Dependencies $dependencies;
    private Parser $parser;

    protected function setUp(): void
    {
        $this->dependencies = new Dependencies(new TopLevel());
        $this->parser = new Parser();

        // The tests that don't verify interactions on the CLI stub it out.
        // testHandleReturnsFalseWhenNoModuleHandles wants writeln expectations,
        // so it replaces the stub with its own mock before instantiating Cli.
        $this->dependencies->setInstance(HordeCli::class, $this->createStub(HordeCli::class));
        $this->dependencies->setInstance(Parser::class, $this->parser);
    }

    public function testConstructorCreatesCliInstance(): void
    {
        $cli = new Cli($this->dependencies);
        $this->assertInstanceOf(Cli::class, $cli);
    }

    public function testConstructorDisablesInterspersedArgsOnParser(): void
    {
        new Cli($this->dependencies);
        $this->assertFalse($this->parser->allowInterspersedArgs);
    }

    public function testConstructorDiscoversCommandModules(): void
    {
        $cli = new Cli($this->dependencies);

        // Every file under src/Command/*.php becomes a module. At minimum
        // the tree ships help, version, install, activate, configure,
        // create, patch, query, secret, target, test, import — a dozen or
        // more. Assert at least a handful were discovered.
        $this->assertGreaterThanOrEqual(5, $cli->count());
    }

    public function testHandleReturnsFalseWhenNoModuleHandles(): void
    {
        // Replace the stub CLI with a mock that verifies showUsage() ran.
        // All shipped modules return false on empty argv, so Cli::handle
        // falls into showUsage() and returns false.
        $cliMock = $this->createMock(HordeCli::class);
        $cliMock->expects($this->atLeastOnce())->method('writeln');
        $this->dependencies->setInstance(HordeCli::class, $cliMock);

        $cli = new Cli($this->dependencies);
        $this->assertFalse($cli->handle([]));
    }
}
