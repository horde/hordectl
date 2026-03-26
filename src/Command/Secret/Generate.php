<?php

declare(strict_types=1);

namespace Horde\Hordectl\Command\Secret;

use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\Service\ConfigFileWriter;
use Horde\Hordectl\Target;
use Horde\Hordectl\TargetCapabilityTrait;
use Horde\Hordectl\TargetResolver;
use Horde\Injector\Injector;
use Exception;
use RuntimeException;

/**
 * Secret:Generate command - generate new admin_secret
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
class Generate implements Module, ModuleUsage
{
    use ModuleTrait;
    use TargetCapabilityTrait;

    protected HordeCli $cli;
    protected Output $output;
    protected ConfigFileWriter $configWriter;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->output = $dependencies->createOutput($this->cli);
        $this->configWriter = new ConfigFileWriter();
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
        if ($command === 'generate') {
            $this->generateSecret(array_slice($argv, 1));
            return true;
        }

        return false;
    }

    /**
     * Generate new admin_secret and write to conf.php
     *
     * @param array $args Command arguments
     */
    protected function generateSecret(array $args): void
    {
        // Check for --force flag
        $force = in_array('--force', $args);

        $this->cli->writeln();
        $this->output->ok('Generating admin_secret for hordectl REST API...');
        $this->cli->writeln();

        // Determine installation directory and target
        $installDir = null;
        $target = null;
        $overrideDir = $this->getInstallationRootDirOverride($args);

        if ($overrideDir) {
            // Use explicit override
            $installDir = $overrideDir;
            $this->cli->writeln('Using installation root: ' . $installDir);
        } else {
            // Use target system
            try {
                $target = $this->requireFilesystemCapability();
                $installDir = $target->hordeInstallDir;
                $this->cli->writeln('Using target: ' . $target->name);
                $this->cli->writeln('Installation root: ' . $installDir);
            } catch (Exception $e) {
                $this->showNoTargetError();
                return;
            }
        }

        // Construct correct conf.php path (bundle style)
        $confPath = $installDir . '/var/config/horde/conf.php';

        // Check if conf.php exists
        if (!file_exists($confPath)) {
            $this->cli->writeln();
            $this->output->error('Configuration file not found');
            $this->cli->writeln();
            $this->cli->writeln("Expected: {$confPath}");
            $this->cli->writeln();
            $this->cli->writeln('Make sure Horde is properly installed and activated.');
            $this->cli->writeln('Run: hordectl activate');
            $this->cli->writeln();
            return;
        }

        $this->cli->writeln('Config file: ' . $confPath);
        $this->cli->writeln();

        try {
            // Check if secret already exists
            $existingSecret = null;
            try {
                $existingSecret = $this->configWriter->readAdminSecret($confPath);
            } catch (RuntimeException $e) {
                // Config file might not have admin_api section yet
            }

            if ($existingSecret && !$force) {
                $this->output->warn('An admin_secret already exists');
                $this->cli->writeln();
                $this->cli->writeln('Current secret: ' . $this->maskSecret($existingSecret));
                $this->cli->writeln();
                $this->cli->writeln('To replace/rotate the secret, use:');
                $this->cli->writeln('  hordectl secret generate --force');
                $this->cli->writeln();
                $this->cli->writeln('To view the full current secret:');
                $this->cli->writeln('  hordectl secret show');
                $this->cli->writeln();
                return;
            }

            if ($existingSecret) {
                $this->cli->writeln('Existing secret: ' . $this->maskSecret($existingSecret));
                $this->cli->writeln('Rotating to new secret...');
                $this->cli->writeln();
            }

            // Generate new secret
            $this->cli->writeln('Generating cryptographically random secret (128 characters)...');
            $secret = $this->configWriter->generateSecret();

            // Write new secret
            $this->cli->writeln('Writing to conf.php...');
            $this->configWriter->writeAdminSecret($confPath, $secret);
            $this->configWriter->enableAdminApi($confPath);

            // Auto-sync to target config for local targets
            if ($target !== null && $target->isLocal()) {
                $this->cli->writeln('Syncing secret to target config...');
                $this->syncSecretToTarget($target, $secret);
            }

            $this->cli->writeln();
            if ($existingSecret) {
                $this->output->ok('SUCCESS: admin_secret rotated!');
                $this->cli->writeln();
                $this->cli->writeln('Old secret: ' . $this->maskSecret($existingSecret));
                $this->cli->writeln('New secret: ' . $secret);
            } else {
                $this->output->ok('SUCCESS: admin_secret generated!');
                $this->cli->writeln();
                $this->cli->writeln('Your admin_secret:');
                $this->cli->writeln('  ' . $secret);
            }
            $this->cli->writeln();

            if ($target !== null && $target->isLocal()) {
                $this->output->ok('Target config updated automatically');
                $this->cli->writeln();
                $this->cli->writeln("Target '{$target->name}' now has the new secret.");
                $this->cli->writeln('You can immediately use API commands like:');
                $this->cli->writeln('  hordectl test db');
                $this->cli->writeln('  hordectl test cache');
                $this->cli->writeln();
            } else {
                // Only show manual steps when using --installation-root-dir override
                $this->output->warn('IMPORTANT: Store this secret securely!');
                $this->cli->writeln();
                $this->cli->writeln('This secret grants full administrative access to Horde.');
                $this->cli->writeln('Do not share it or commit it to version control.');
                $this->cli->writeln();
                $this->cli->writeln('Next steps:');
                $this->cli->writeln('  1. Update target with this secret:');
                $this->cli->writeln('     hordectl target update <name> --secret=<secret>');
                $this->cli->writeln();
                $this->cli->writeln('  2. Test the connection:');
                $this->cli->writeln('     hordectl test db');
                $this->cli->writeln();
            }
        } catch (Exception $e) {
            $this->cli->writeln();
            $this->output->error('Failed to generate secret');
            $this->cli->writeln();
            $this->cli->writeln($e->getMessage());
            $this->cli->writeln();
        }
    }

    /**
     * Mask secret for display (show first/last 8 chars)
     *
     * @param string $secret Secret to mask
     * @return string Masked secret
     */
    protected function maskSecret(string $secret): string
    {
        if (strlen($secret) <= 20) {
            return '***';
        }

        return substr($secret, 0, 8) . '...' . substr($secret, -8);
    }

    /**
     * Sync generated secret to target config
     *
     * Updates the target's adminSecret field in hordectl config.
     *
     * @param Target $target The target to update
     * @param string $secret The new secret to sync
     */
    protected function syncSecretToTarget(Target $target, string $secret): void
    {
        $config = new ConfigManager();
        $resolver = new TargetResolver();

        // Create updated target with new secret
        $updatedTarget = new Target(
            name: $target->name,
            type: $target->type,
            hordeBase: $target->hordeBase,
            hordeInstallDir: $target->hordeInstallDir,
            endpoint: $target->endpoint,
            adminSecret: $secret,
            verifySsl: $target->verifySsl,
            description: $target->description,
            autoDetected: $target->autoDetected,
            fromEnv: $target->fromEnv
        );

        $resolver->saveTarget($config, $updatedTarget);
    }

    /**
     * Get installation root directory override from arguments
     *
     * @param array $args Command arguments
     * @return string|null Installation root path or null
     */
    protected function getInstallationRootDirOverride(array $args): ?string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--installation-root-dir=')) {
                return substr($arg, strlen('--installation-root-dir='));
            }
        }
        return null;
    }

    /**
     * Show error when no target is configured
     */
    protected function showNoTargetError(): void
    {
        $this->cli->writeln();
        $this->output->error('No active target configured');
        $this->cli->writeln();
        $this->cli->writeln('Please either:');
        $this->cli->writeln('  1. Add and set a target:');
        $this->cli->writeln('       hordectl target add mysite --type=local --path=/var/www/horde');
        $this->cli->writeln('       hordectl target use mysite');
        $this->cli->writeln('       hordectl secret generate');
        $this->cli->writeln();
        $this->cli->writeln('  2. Specify installation path directly:');
        $this->cli->writeln('       hordectl secret generate --installation-root-dir=/var/www/horde');
        $this->cli->writeln();
    }

    /**
     * Get module usage information
     *
     * @return string
     */
    public function getUsage(): string
    {
        return 'Generate new admin_secret and write to conf.php';
    }

    /**
     * Get module title
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'generate';
    }

    /**
     * Get module description
     *
     * @return array
     */
    public function getUsageDescription(): array
    {
        return [
            'Generate or rotate admin_secret for hordectl REST API authentication',
            '',
            'Uses the current target to locate conf.php and write the secret.',
            'Generates a cryptographically random 128-character secret.',
            '',
            'Usage:',
            '  hordectl secret generate                    # Use current target',
            '  hordectl secret generate --force            # Rotate existing secret',
            '  hordectl secret generate --installation-root-dir=/var/www/horde  # Override',
            '',
            'Options:',
            '  --force                      Replace existing secret (rotation)',
            '  --installation-root-dir      Override: use this path instead of current target',
        ];
    }
}
