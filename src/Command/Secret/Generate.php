<?php

declare(strict_types=1);

namespace Horde\Hordectl\Command\Secret;

use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\Service\ConfigFileWriter;
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

        // Find Horde installation
        $hordeDir = $this->getHordeDir($args);
        if (!$hordeDir) {
            $this->cli->writeln();
            $this->output->error('Could not find Horde installation');
            $this->cli->writeln();
            $this->cli->writeln('Please specify the installation root directory:');
            $this->cli->writeln('  hordectl secret generate --installation-root-dir=/var/www/horde');
            $this->cli->writeln();
            $this->cli->writeln('The installation root is the directory containing composer.json');
            $this->cli->writeln('and vendor/horde/horde subdirectory.');
            $this->cli->writeln();
            return;
        }

        $confPath = $hordeDir . '/vendor/horde/horde/config/conf.php';

        // Check if conf.php exists
        if (!file_exists($confPath)) {
            $this->cli->writeln();
            $this->output->error('Configuration file not found');
            $this->cli->writeln();
            $this->cli->writeln("Expected: {$confPath}");
            $this->cli->writeln();
            $this->cli->writeln('Make sure Horde is properly installed and configured.');
            $this->cli->writeln();
            return;
        }

        $this->cli->writeln('Installation root: ' . $hordeDir);
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
            $this->output->warn('IMPORTANT: Store this secret securely!');
            $this->cli->writeln();
            $this->cli->writeln('This secret grants full administrative access to Horde.');
            $this->cli->writeln('Do not share it or commit it to version control.');
            $this->cli->writeln();

            if ($existingSecret) {
                $this->output->warn('Old hordectl connections will break immediately!');
                $this->cli->writeln();
                $this->cli->writeln('Update ~/.hordectl/config.yml with the new secret:');
                $this->cli->writeln();
                $this->cli->writeln('  horde:');
                $this->cli->writeln('    admin_secret: ' . $secret);
                $this->cli->writeln();
            } else {
                $this->cli->writeln('Next steps:');
                $this->cli->writeln('  1. Configure hordectl with this secret:');
                $this->cli->writeln('     Create ~/.hordectl/config.yml:');
                $this->cli->writeln();
                $this->cli->writeln('     horde:');
                $this->cli->writeln('       base_url: http://localhost/horde');
                $this->cli->writeln('       admin_secret: ' . $secret);
                $this->cli->writeln();
                $this->cli->writeln('  2. Test the connection:');
                $this->cli->writeln('     hordectl info');
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
     * Get Horde installation root directory from arguments
     *
     * @param array $args Command arguments
     * @return string|null Horde installation root path or null
     */
    protected function getHordeDir(array $args): ?string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--installation-root-dir=')) {
                return substr($arg, strlen('--installation-root-dir='));
            }
        }

        // Try to find Horde installation automatically
        // Look for directory containing composer.json and vendor/horde/horde subdirectory
        $paths = [
            getcwd(),
            '/var/www/horde',
            '/usr/share/horde',
            dirname(getcwd()), // Parent directory
        ];

        foreach ($paths as $path) {
            // Check for composer.json and vendor/horde/horde/config/conf.php
            if (file_exists($path . '/composer.json')
                && file_exists($path . '/vendor/horde/horde/config/conf.php')) {
                return $path;
            }
        }

        return null;
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
            'Generates a cryptographically random 128-character secret',
            'and writes it to Horde\'s conf.php configuration file.',
            '',
            'If a secret already exists, you must use --force to replace it.',
            'Re-running with --force rotates the secret (useful for periodic rotation).',
            '',
            'Usage:',
            '  hordectl secret generate                    # First-time generation',
            '  hordectl secret generate --force            # Rotate existing secret',
            '  hordectl secret generate --installation-root-dir=/var/www/horde',
            '',
            'Options:',
            '  --force                      Replace existing secret (rotation)',
            '  --installation-root-dir      Path to Horde installation root',
            '                               (directory with composer.json, auto-detected)',
        ];
    }
}
