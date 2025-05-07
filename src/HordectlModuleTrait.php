<?php
/**
 * HordectlModuleTrait provides implementation for the HordectlModuleInterface
 *
 */
namespace Horde\Hordectl;
trait HordectlModuleTrait {

    private $_parentModule;
    private $_parsed;
    private $_positional;
    private $_parser;

    public function isRootModule()
    {
        return $this->getParentModule() === $this;
    }

    public function getParentModule() : \Horde_Cli_Modular_Module
    {
        return $this->_parentModule ?? $this;
    }

    public function setParentModule(\Horde_Cli_Modular_Module $module)
    {
        // TODO: prevent circular relation
        $this->_parentModule = $module;
    }

    public function getUsage(): string
    {
        return '';
    }

    public function getBaseOptions()
    {
        return [];
    }

    public function hasOptionGroup()
    {
        return false;
    }

    public function getOptionGroupDescription()
    {
        return '';
    }

    public function getOptionGroupOptions($action = null)
    {
        return [];
    }

    public function getOptionGroupTitle()
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
    public function getTitle()
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    /**
     * Get the list of possible arguments for this module's position
     *
     * If a module implements a positional, it will be extracted before
     * the commandline is evaluated
     *
     * Default implementation, override as needed
     */
    public function getPositionalArgs() : array
    {
        return [\Horde_String::lower((new \ReflectionClass($this))->getShortName())];
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
            $group = new \Horde_Argv_OptionGroup(
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

        list($values, $rest) = $this->_parser->parseArgs($localArgv);
    
        return $this->_parsed;
    }
}