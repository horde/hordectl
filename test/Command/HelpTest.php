<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test\Command;

use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\Command\Help;
use Horde\Hordectl\Dependencies;
use Horde\Hordectl\Output;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test the Help command
 *
 * Help is a leaf-level module: its handle() checks argv[0] == 'help' and, if
 * so, prints its usage. It does not consult a Horde registry or discover apps
 * from the target install — that surface belongs to `hordectl query apps`
 * which speaks to the target via AdminApiClient. See
 * src/Command/Query/Apps.php.
 */
#[CoversClass(Help::class)]
class HelpTest extends TestCase
{
    /**
     * Build the injector stub that Help's constructor consumes.
     *
     * Help asks for Horde\Cli\Cli, Horde\Argv\Parser, and calls
     * createOutput($cli). The stubs are just wired to return the given
     * doubles; no interaction is verified on the injector itself here.
     * Tests that want to verify injector behavior create their own
     * mock and pass through this same wiring shape.
     */
    private function injectorFor(HordeCli $cli, Parser $parser): Dependencies
    {
        $injector = $this->createStub(Dependencies::class);
        $injector->method('getInstance')
            ->willReturnCallback(function ($class) use ($cli, $parser) {
                if ($class === HordeCli::class) {
                    return $cli;
                }
                if ($class === Parser::class) {
                    return $parser;
                }
                return null;
            });
        $injector->method('createOutput')
            ->willReturn(new Output($cli));

        return $injector;
    }

    public function testConstructorCreatesHelpInstance(): void
    {
        $help = new Help($this->injectorFor(
            $this->createStub(HordeCli::class),
            $this->createStub(Parser::class),
        ));

        $this->assertInstanceOf(Help::class, $help);
    }

    public function testHandleReturnsFalseOnEmptyArgv(): void
    {
        $help = new Help($this->injectorFor(
            $this->createStub(HordeCli::class),
            $this->createStub(Parser::class),
        ));

        $this->assertFalse($help->handle([]));
    }

    public function testHandleReturnsFalseWhenNotHelpCommand(): void
    {
        $help = new Help($this->injectorFor(
            $this->createStub(HordeCli::class),
            $this->createStub(Parser::class),
        ));

        $this->assertFalse($help->handle(['other']));
    }

    public function testHandleWritesHelpWhenHelpCommand(): void
    {
        $cli = $this->createMock(HordeCli::class);

        // The module identifies itself by writing at least one line that
        // contains the word "help" (case-insensitive).
        $sawHelp = false;
        $cli->expects($this->atLeastOnce())
            ->method('writeln')
            ->willReturnCallback(function ($message = '') use (&$sawHelp) {
                if (is_string($message) && stripos($message, 'help') !== false) {
                    $sawHelp = true;
                }
            });

        $help = new Help($this->injectorFor($cli, $this->createStub(Parser::class)));

        $this->assertTrue($help->handle(['help']));
        $this->assertTrue($sawHelp, "Expected 'help' text in output");
    }
}
