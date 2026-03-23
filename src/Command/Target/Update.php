<?php

declare(strict_types=1);

namespace Horde\Hordectl\Command\Target;

use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\Exception\TargetNotFoundException;
use Horde\Hordectl\HordectlModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\Target;
use Horde\Hordectl\TargetResolver;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;

/**
 * Target update command
 *
 * Updates an existing target's configuration.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Update implements Module
{
    use HordectlModuleTrait;

    protected HordeCli $cli;
    protected Output $output;
    protected Parser $parser;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->output = $dependencies->createOutput($this->cli);
        $this->parser = $dependencies->getInstance(Parser::class);
    }

    public function handle(array $argv = []): bool
    {
        if (count($argv) < 1 || $argv[0] !== 'update') {
            return false;
        }

        // Remove 'update' from argv
        array_shift($argv);

        // Parse arguments
        $this->parser->allowInterspersedArgs = true;
        $this->parser->addOption(
            '--path',
            [
                'help' => 'Horde installation directory (for local targets)',
            ]
        );
        $this->parser->addOption(
            '--endpoint',
            [
                'help' => 'API endpoint URL (empty string to remove)',
            ]
        );
        $this->parser->addOption(
            '--secret',
            [
                'help' => 'Admin API secret',
            ]
        );
        $this->parser->addOption(
            '--description',
            [
                'help' => 'Target description',
            ]
        );

        [$opts, $args] = $this->parser->parseArgs($argv);

        if (count($args) < 1) {
            $this->cli->fatal("Missing target name. Usage: hordectl target update <name> [options]");
            return false;
        }

        $name = $args[0];
        $config = new ConfigManager();
        $resolver = new TargetResolver();

        try {
            // Get existing target
            $target = $resolver->getTarget($config, $name);

            // Build updated target (merge with existing)
            $updatedTarget = $this->updateTarget($target, (array) $opts);

            // Save
            $resolver->saveTarget($config, $updatedTarget);

            $this->cli->writeln(sprintf(
                "Target '%s' updated successfully.",
                $name
            ));

        } catch (TargetNotFoundException $e) {
            $this->cli->fatal($e->getMessage());
        }

        return true;
    }

    protected function updateTarget(Target $existing, array $opts): Target
    {
        // Start with existing values
        $hordeBase = $existing->hordeBase;
        $hordeInstallDir = $existing->hordeInstallDir;
        $endpoint = $existing->endpoint;
        $adminSecret = $existing->adminSecret;
        $description = $existing->description;

        // Update path for local targets
        if (isset($opts['path']) && $existing->isLocal()) {
            $path = rtrim($opts['path'], '/');
            $hordeInstallDir = $path;
            $hordeBase = $path;
            if (!str_ends_with($path, '/vendor/horde/horde')) {
                $hordeBase = $path . '/vendor/horde/horde';
            }
        }

        // Update endpoint (empty string removes it)
        if (isset($opts['endpoint'])) {
            $endpoint = $opts['endpoint'] !== '' ? $opts['endpoint'] : null;
        }

        // Update secret
        if (isset($opts['secret'])) {
            $adminSecret = $opts['secret'];
        }

        // Update description
        if (isset($opts['description'])) {
            $description = $opts['description'] !== '' ? $opts['description'] : null;
        }

        return new Target(
            name: $existing->name,
            type: $existing->type,
            hordeBase: $hordeBase,
            hordeInstallDir: $hordeInstallDir,
            endpoint: $endpoint,
            adminSecret: $adminSecret,
            verifySsl: $existing->verifySsl,
            description: $description,
            autoDetected: $existing->autoDetected,
            fromEnv: $existing->fromEnv
        );
    }
}
