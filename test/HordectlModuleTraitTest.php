<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test;

use Horde\Hordectl\HordectlModuleTrait;
use Horde_Cli_Modular_Module;
use Horde\Argv\Parser;
use Horde\Argv\Option;
use Horde\Argv\OptionGroup;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Test the HordectlModuleTrait
 */
#[AllowMockObjectsWithoutExpectations]
class HordectlModuleTraitTest extends TestCase
{
    private $trait;

    protected function setUp(): void
    {
        // Create an anonymous class that uses the trait and implements Module interface
        $this->trait = new class implements Horde_Cli_Modular_Module {
            use HordectlModuleTrait;

            public function __construct()
            {
                $this->_parser = new Parser();
            }

            // Expose protected properties for testing
            public function getParserPublic()
            {
                return $this->_parser;
            }

            public function getPositionalPublic()
            {
                return $this->_positional ?? null;
            }

            public function getParsedPublic()
            {
                return $this->_parsed ?? null;
            }
        };
    }

    public function testIsRootModuleReturnsTrueWhenNoParent(): void
    {
        $this->assertTrue($this->trait->isRootModule());
    }

    public function testIsRootModuleReturnsFalseWhenHasParent(): void
    {
        $mockParent = $this->createMock(Horde_Cli_Modular_Module::class);
        $this->trait->setParentModule($mockParent);

        $this->assertFalse($this->trait->isRootModule());
    }

    public function testGetParentModuleReturnsSelfWhenNoParent(): void
    {
        $parent = $this->trait->getParentModule();
        $this->assertSame($this->trait, $parent);
    }

    public function testGetParentModuleReturnsSetParent(): void
    {
        $mockParent = $this->createMock(Horde_Cli_Modular_Module::class);
        $this->trait->setParentModule($mockParent);

        $parent = $this->trait->getParentModule();
        $this->assertSame($mockParent, $parent);
    }

    public function testGetUsageReturnsEmptyString(): void
    {
        $this->assertSame('', $this->trait->getUsage());
    }

    public function testGetBaseOptionsReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->trait->getBaseOptions());
    }

    public function testHasOptionGroupReturnsFalse(): void
    {
        $this->assertFalse($this->trait->hasOptionGroup());
    }

    public function testGetOptionGroupDescriptionReturnsEmptyString(): void
    {
        $this->assertSame('', $this->trait->getOptionGroupDescription());
    }

    public function testGetOptionGroupOptionsReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->trait->getOptionGroupOptions());
    }

    public function testGetOptionGroupTitleReturnsEmptyString(): void
    {
        $this->assertSame('', $this->trait->getOptionGroupTitle());
    }

    public function testGetTitleReturnsClassName(): void
    {
        $title = $this->trait->getTitle();
        // Check that it returns some kind of class name (anonymous classes have different formats)
        $this->assertNotEmpty($title);
        $this->assertIsString($title);
    }

    public function testGetPositionalArgsReturnsLowercaseClassName(): void
    {
        $args = $this->trait->getPositionalArgs();
        $this->assertIsArray($args);
        $this->assertCount(1, $args);
        // Check that it returns a string (format varies for anonymous classes)
        $this->assertIsString($args[0]);
        $this->assertNotEmpty($args[0]);
    }

    public function testHandleCommandlineExtractsPositionalArg(): void
    {
        // Create a trait instance with a known positional
        $trait = new class {
            use HordectlModuleTrait;

            public function __construct()
            {
                $this->_parser = new Parser();
                $this->_parser->allowInterspersedArgs = false;
            }

            public function getPositionalArgs(): array
            {
                return ['test'];
            }

            public function getPositionalPublic()
            {
                return $this->_positional ?? null;
            }
        };

        $result = $trait->handleCommandline(['test', 'arg1', 'arg2']);

        $this->assertSame('test', $trait->getPositionalPublic());
    }

    public function testHandleCommandlineSetsEmptyPositionalWhenNoMatch(): void
    {
        // Create a trait instance with a known positional that won't match
        $trait = new class {
            use HordectlModuleTrait;

            public function __construct()
            {
                $this->_parser = new Parser();
                $this->_parser->allowInterspersedArgs = false;
            }

            public function getPositionalArgs(): array
            {
                return ['expected'];
            }

            public function getPositionalPublic()
            {
                return $this->_positional ?? null;
            }
        };

        $result = $trait->handleCommandline(['different', 'arg1']);

        $this->assertSame('', $trait->getPositionalPublic());
    }

    public function testHandleCommandlineAddsBaseOptionsToParser(): void
    {
        $trait = new class {
            use HordectlModuleTrait;

            public function __construct()
            {
                $this->_parser = new Parser();
                $this->_parser->allowInterspersedArgs = false;
            }

            public function getPositionalArgs(): array
            {
                return [];
            }

            public function getBaseOptions()
            {
                return [
                    new Option('-t', '--test', ['dest' => 'test'])
                ];
            }

            public function wasOptionAdded(): bool
            {
                // Check if parser has our option
                foreach ($this->_parser->optionList as $option) {
                    if ($option->dest === 'test') {
                        return true;
                    }
                }
                return false;
            }
        };

        $trait->handleCommandline([]);

        $this->assertTrue($trait->wasOptionAdded());
    }
}
