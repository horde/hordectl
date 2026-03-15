<?php

declare(strict_types=1);

namespace Horde\Hordectl\Command\Target;

use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\Exception\TargetNotFoundException;
use Horde\Hordectl\HordectlModuleTrait;
use Horde\Hordectl\TargetResolver;
use Horde\Injector\Injector;
use Horde_Cli;
use Horde_Cli_Modular_Module as Module;

/**
 * Target delete command
 *
 * Deletes an existing target.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Delete implements Module
{
    use HordectlModuleTrait;

    protected Horde_Cli $cli;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance('\Horde_Cli');
    }

    public function handle(array $argv = [], ?ConfigManager $testConfig = null): bool
    {
        if (count($argv) < 1 || $argv[0] !== 'delete') {
            return false;
        }

        if (!isset($argv[1])) {
            $this->cli->fatal("Missing target name. Usage: hordectl target delete <name>");
            return false;
        }

        $targetName = $argv[1];
        $config = $testConfig ?? new ConfigManager();
        $resolver = new TargetResolver();

        try {
            // Check if target exists
            $resolver->getTarget($config, $targetName);

            // Warn if deleting current target
            $currentTarget = $config->get('current-target');
            if ($currentTarget === $targetName) {
                $this->cli->writeln($this->cli->yellow(
                    "Warning: Deleting current target. You will need to use 'hordectl target use <name>' to select another target."
                ));
            }

            // Delete target
            $resolver->deleteTarget($config, $targetName);

            $this->cli->writeln(sprintf(
                "Target '%s' deleted",
                $targetName
            ));

        } catch (TargetNotFoundException $e) {
            $this->cli->fatal($e->getMessage());
        }

        return true;
    }
}
