<?php

declare(strict_types=1);

namespace Horde\Hordectl\Command\Target;

use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\Exception\TargetNotFoundException;
use Horde\Hordectl\HordectlModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\TargetResolver;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;

/**
 * Target use command
 *
 * Switches to a different target.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class UseTarget implements Module
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

    public function handle(array $argv = [], ?ConfigManager $testConfig = null): bool
    {
        if (count($argv) < 1 || $argv[0] !== 'use') {
            return false;
        }

        if (!isset($argv[1])) {
            $this->cli->fatal("Missing target name. Usage: hordectl target use <name>");
            return false;
        }

        $targetName = $argv[1];
        $config = $testConfig ?? new ConfigManager();
        $resolver = new TargetResolver();

        try {
            // Verify target exists
            $target = $resolver->getTarget($config, $targetName);

            // Set as current
            $resolver->setCurrentTarget($config, $targetName);

            // Show confirmation
            $this->cli->writeln(sprintf(
                "Switched to target '%s' (%s: %s)",
                $target->name,
                $target->type->value,
                $target->getLocationString()
            ));

            // Show capability reminder for remote targets
            if ($target->isRemote()) {
                $this->cli->writeln(
                    "Note: Local filesystem commands (configure, activate) are not available on remote targets."
                );
            }

        } catch (TargetNotFoundException $e) {
            $this->cli->fatal($e->getMessage());
        }

        return true;
    }
}
