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
 * Target show command
 *
 * Shows detailed information about a target.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Show implements Module
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
        if (count($argv) < 1 || $argv[0] !== 'show') {
            return false;
        }

        if (!isset($argv[1])) {
            $this->cli->fatal("Missing target name. Usage: hordectl target show <name>");
            return false;
        }

        $targetName = $argv[1];
        $config = new ConfigManager();
        $resolver = new TargetResolver();

        try {
            $target = $resolver->getTarget($config, $targetName);
            $isCurrent = $config->get('current-target') === $targetName;

            // Header
            $this->cli->writeln(sprintf(
                "%s '%s'%s",
                $this->cli->bold("Target:"),
                $target->name,
                $isCurrent ? " " . $this->cli->green("(current)") : ""
            ));
            $this->cli->writeln();

            // Basic info
            $this->cli->writeln(sprintf("  Type: %s", $target->type->value));

            // Type-specific info
            if ($target->isLocal()) {
                if ($target->hordeBase !== null) {
                    $this->cli->writeln(sprintf("  Horde Base: %s", $target->hordeBase));
                }
                if ($target->hordeInstallDir !== null) {
                    $this->cli->writeln(sprintf("  Install Directory: %s", $target->hordeInstallDir));
                }
            }

            if ($target->endpoint !== null) {
                $this->cli->writeln(sprintf("  API Endpoint: %s", $target->endpoint));
            }

            if ($target->adminSecret !== null) {
                $secretDisplay = empty($target->adminSecret)
                    ? "(empty - reads from conf.php)"
                    : "(configured - " . substr($target->adminSecret, 0, 10) . "...)";
                $this->cli->writeln(sprintf("  Admin Secret: %s", $secretDisplay));
            }

            if (!$target->verifySsl) {
                $this->cli->writeln(sprintf("  Verify SSL: %s", "false"));
            }

            if ($target->description !== null && $target->description !== '') {
                $this->cli->writeln(sprintf("  Description: %s", $target->description));
            }

            // Flags
            if ($target->autoDetected) {
                $this->cli->writeln("  Auto-detected: yes");
            }
            if ($target->fromEnv) {
                $this->cli->writeln("  From Environment: yes");
            }

            // Capabilities
            $this->cli->writeln();
            $this->cli->writeln($this->cli->bold("Capabilities:"));
            $this->cli->writeln(sprintf(
                "  Filesystem commands: %s",
                $target->supportsFilesystemCommands() ? $this->cli->green("yes") : $this->cli->red("no")
            ));
            $this->cli->writeln(sprintf(
                "  API commands: %s",
                $target->supportsApiCommands() ? $this->cli->green("yes") : $this->cli->red("no")
            ));

        } catch (TargetNotFoundException $e) {
            $this->cli->fatal($e->getMessage());
        }

        return true;
    }
}
