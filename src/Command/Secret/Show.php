<?php

declare(strict_types=1);

namespace Horde\Hordectl\Command\Secret;

use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;
use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\TargetResolver;
use Horde\Injector\Injector;

/**
 * Secret:Show command - display current admin_secret from hordectl config
 *
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Hordectl
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Show implements Module, ModuleUsage
{
    use ModuleTrait;

    protected HordeCli $cli;
    protected Output $output;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->output = $dependencies->createOutput($this->cli);
    }

    /**
     * Decide if this module handles the commandline
     *
     * @param array $argv The arguments for the parser to digest
     * @return bool
     */
    public function handle(array $argv = []): bool
    {
        if (count($argv) < 1) {
            return false;
        }

        $command = $argv[0];
        if ($command === 'show') {
            $this->showSecret(array_slice($argv, 1));
            return true;
        }

        return false;
    }

    /**
     * Display current admin_secret from hordectl config
     *
     * @param array $args Command arguments
     */
    protected function showSecret(array $args): void
    {
        $this->cli->writeln();
        $this->output->ok('Display admin_secret');
        $this->cli->writeln();

        $config = new ConfigManager();
        $resolver = new TargetResolver();
        $currentTargetName = $config->get('current-target');

        if (!$currentTargetName) {
            $this->output->error('No target configured');
            $this->cli->writeln();
            $this->cli->writeln('Configure a target first with:');
            $this->cli->writeln('  hordectl target add <name> ...');
            $this->cli->writeln();
            return;
        }

        $target = $resolver->getTarget($config, $currentTargetName);
        if (!$target) {
            $this->output->error(sprintf('Target "%s" not found', $currentTargetName));
            $this->cli->writeln();
            return;
        }

        $this->cli->writeln('Target: ' . $target->name);
        $this->cli->writeln('Type: ' . $target->type->value);
        if ($target->endpoint) {
            $this->cli->writeln('Endpoint: ' . $target->endpoint);
        }
        $this->cli->writeln();

        if (!$target->adminSecret) {
            $this->output->warn('No admin_secret configured for this target');
            $this->cli->writeln();
            $this->cli->writeln('Generate and configure a secret with:');
            $this->cli->writeln('  hordectl secret generate');
            $this->cli->writeln();
            return;
        }

        $this->output->ok('Current admin_secret:');
        $this->cli->writeln();
        $this->cli->writeln('  ' . $target->adminSecret);
        $this->cli->writeln();
        $this->output->warn('WARNING: Keep this secret secure!');
        $this->cli->writeln();
        $this->cli->writeln('This secret grants full administrative access to Horde.');
        $this->cli->writeln('Do not share it or commit it to version control.');
        $this->cli->writeln();
    }

    /**
     * Get module usage information
     *
     * @return string
     */
    public function getUsage(): string
    {
        return 'Display admin_secret from current target configuration';
    }

    /**
     * Get module title
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'show';
    }

    /**
     * Get module description
     *
     * @return array
     */
    public function getUsageDescription(): array
    {
        return [
            'Display admin_secret for the current target',
            '',
            'Reads and displays the admin_secret from hordectl configuration.',
            'Shows the secret for the currently active target.',
            '',
            'Usage:',
            '  hordectl secret show',
            '',
            'Security Warning:',
            '  This secret grants full administrative access to Horde.',
            '  Keep it secure and do not share it.',
        ];
    }
}
