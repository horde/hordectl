<?php

/**
 * HordectlModuleTrait provides implementation for the HordectlModuleInterface
 *
 */

namespace Horde\Hordectl;

use Horde\Injector\Injector;
use Horde\Argv\OptionGroup;
use Horde\Cli\Modular\Module;
use Horde\Util\HordeString;
use ReflectionClass;

trait HordectlModuleTrait
{
    protected Injector $dependencies;
    private $_parentModule;
    private $_parsed;
    private $_positional;
    private $_parser;

    public function isRootModule()
    {
        return $this->getParentModule() === $this;
    }

    public function getParentModule(): Module
    {
        return $this->_parentModule ?? $this;
    }

    public function setParentModule(Module $module)
    {
        // TODO: prevent circular relation
        $this->_parentModule = $module;
    }

    public function getUsage(): string
    {
        return '';
    }

    public function getBaseOptions(): iterable
    {
        return [];
    }

    public function hasOptionGroup(): bool
    {
        return false;
    }

    public function getOptionGroupDescription(): string
    {
        return '';
    }

    public function getOptionGroupOptions($action = null): iterable
    {
        return [];
    }

    public function getOptionGroupTitle(): string
    {
        return '';
    }

    /**
     * Default implementation of getTitle
     *
     * Override this as needed
     *
     * @return string The title of the module
     */
    public function getTitle(): string
    {
        return (new ReflectionClass($this))->getShortName();
    }

    /**
     * Get the list of possible arguments for this module's position
     *
     * If a module implements a positional, it will be extracted before
     * the commandline is evaluated
     *
     * Default implementation, override as needed
     */
    public function getPositionalArgs(): array
    {
        return [HordeString::lower((new ReflectionClass($this))->getShortName())];
    }

    /**
     * Handle commandline given to this module
     *
     * Commandline options and keywords belonging to parent modules have
     * already been removed. The module's PositionalArgs will be checked in
     * second position and removed. Options will only be regarded until the
     * following positional argument.
     *
     * Position 0 in argv is assumed to be the program name
     *         // non-interspersed parser may not tolerate position 0
     */
    public function handleCommandline(array $argv)
    {
        $localArgv = $argv;
        $positionals = $this->getPositionalArgs();
        if (empty($positionals)) {
            $this->_positional = '';
        } elseif (!empty($argv[0]) && in_array($argv[0], $positionals)) {
            $this->_positional = array_shift($localArgv);
        } else {
            $this->_positional = '';
        }
        foreach ($this->getBaseOptions() as $option) {
            $this->_parser->addOption($option);
        }
        if ($this->hasOptionGroup()) {
            $group = new OptionGroup(
                $this->_parser,
                $this->getOptionGroupTitle(),
                $this->getOptionGroupDescription()
            );
            foreach ($this->getOptionGroupOptions() as $option) {
                $group->addOption($option);
            }
            $this->_parser->addOptionGroup($group);
        }
        $this->_parsed = $this->_parser->parseArgs($localArgv);

        [$values, $rest] = $this->_parser->parseArgs($localArgv);

        return $this->_parsed;
    }
}
