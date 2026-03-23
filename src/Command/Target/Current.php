<?php

declare(strict_types=1);

namespace Horde\Hordectl\Command\Target;

use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\Exception\NoCurrentTargetException;
use Horde\Hordectl\HordectlModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\TargetResolver;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;

/**
 * Target current command
 *
 * Shows the currently active target.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Current implements Module
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
        if (count($argv) < 1 || $argv[0] !== 'current') {
            return false;
        }

        $config = new ConfigManager();
        $resolver = new TargetResolver();

        try {
            $target = $resolver->getCurrentTarget($config);

            $this->cli->writeln(sprintf(
                "%s (%s, %s)",
                $target->name,
                $target->type->value,
                $target->getLocationString()
            ));

        } catch (NoCurrentTargetException $e) {
            $this->cli->fatal($e->getMessage());
        }

        return true;
    }
}
