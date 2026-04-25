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
use Horde\Cli\Modular\Module;

/**
 * Target rename command
 *
 * Renames an existing target.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Rename implements Module
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
        if (count($argv) < 1 || $argv[0] !== 'rename') {
            return false;
        }

        if (!isset($argv[1]) || !isset($argv[2])) {
            $this->cli->fatal("Usage: hordectl target rename <old-name> <new-name>");
            return false;
        }

        $oldName = $argv[1];
        $newName = $argv[2];

        $config = $testConfig ?? new ConfigManager();
        $resolver = new TargetResolver();

        try {
            $resolver->renameTarget($config, $oldName, $newName);

            $this->cli->writeln(sprintf(
                "Target '%s' renamed to '%s'",
                $oldName,
                $newName
            ));

        } catch (TargetNotFoundException $e) {
            $this->cli->fatal($e->getMessage());
        }

        return true;
    }
}
