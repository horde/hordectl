<?php

declare(strict_types=1);

namespace Horde\Hordectl\Command\Target;

use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\Exception\TargetNotFoundException;
use Horde\Hordectl\HordectlModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\Target;
use Horde\Hordectl\TargetResolver;
use Horde\Injector\Injector;
use Horde\Cli\Modular\Module;

/**
 * Target sync-secret command
 *
 * Syncs API secret from conf.php for local targets.
 * Provides manual credential recovery when API auth fails.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class SyncSecret implements Module
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
        if (count($argv) < 1 || $argv[0] !== 'sync-secret') {
            return false;
        }

        if (!isset($argv[1])) {
            $this->cli->fatal("Missing target name. Usage: hordectl target sync-secret <name>");
            return false;
        }

        $targetName = $argv[1];
        $config = new ConfigManager();
        $resolver = new TargetResolver();

        try {
            $target = $resolver->getTarget($config, $targetName);

            // Only works for local targets
            if (!$target->isLocal()) {
                $this->cli->fatal(
                    "Cannot sync secret for remote target '{$targetName}'.\n"
                    . "Remote targets cannot be reconfigured by hordectl.\n"
                    . "You must obtain the admin_secret from the server manually."
                );
                return false;
            }

            // Read secret from conf.php
            $confPath = $target->hordeInstallDir . '/var/config/horde/conf.php';

            if (!file_exists($confPath)) {
                $this->cli->fatal(
                    "Configuration file not found: {$confPath}\n"
                    . "Cannot read admin_secret from conf.php."
                );
                return false;
            }

            // Load conf.php
            $conf = [];
            require $confPath;

            $adminSecret = $conf['admin_api']['admin_secret'] ?? null;

            if ($adminSecret === null || $adminSecret === '') {
                $this->cli->writeln(
                    $this->cli->yellow("Warning: admin_secret not configured in conf.php or is empty.")
                );
                $this->cli->writeln("Use 'hordectl secret:generate' to create one.");
                return true;
            }

            // Update target with new secret
            $updatedTarget = new Target(
                name: $target->name,
                type: $target->type,
                hordeBase: $target->hordeBase,
                hordeInstallDir: $target->hordeInstallDir,
                endpoint: $target->endpoint,
                adminSecret: $adminSecret,
                verifySsl: $target->verifySsl,
                description: $target->description,
                autoDetected: $target->autoDetected,
                fromEnv: $target->fromEnv
            );

            $resolver->saveTarget($config, $updatedTarget);

            $this->cli->writeln(sprintf(
                "Synced admin_secret from %s",
                $confPath
            ));
            $this->cli->writeln(sprintf(
                "Target '%s' updated successfully.",
                $targetName
            ));

        } catch (TargetNotFoundException $e) {
            $this->cli->fatal($e->getMessage());
        }

        return true;
    }
}
