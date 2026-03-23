<?php

declare(strict_types=1);

namespace Horde\Hordectl\Command\Target;

use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\HordectlModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\TargetResolver;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;

/**
 * Target list command
 *
 * Lists all configured targets in table format.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ListTargets implements Module
{
    use HordectlModuleTrait;

    protected HordeCli $cli;
    protected Output $output;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->output = $dependencies->createOutput($this->cli);
    }

    public function handle(array $argv = []): bool
    {
        if (count($argv) < 1 || $argv[0] !== 'list') {
            return false;
        }

        $config = new ConfigManager();
        $resolver = new TargetResolver();

        $targets = $resolver->getAllTargets($config);
        $currentName = $config->get('current-target');

        if (empty($targets)) {
            $this->cli->writeln("No targets configured.");
            $this->cli->writeln("Use 'hordectl target add' to add a target.");
            return true;
        }

        // Print header
        $this->cli->writeln(sprintf(
            "%-8s %-20s %-8s %s",
            "CURRENT",
            "NAME",
            "TYPE",
            "LOCATION"
        ));

        // Print targets
        foreach ($targets as $name => $target) {
            $current = ($name === $currentName) ? '*' : ' ';

            $this->cli->writeln(sprintf(
                "%-8s %-20s %-8s %s",
                $current,
                $name,
                $target->type->value,
                $target->getAbbreviatedLocation(50)
            ));
        }

        return true;
    }
}
