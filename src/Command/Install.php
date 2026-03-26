<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Command;

use Horde\Argv\Option;
use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use RuntimeException;

/**
 * Install Horde by downloading latest horde/bundle
 *
 * Downloads the latest tagged release of horde/bundle from GitHub
 * to the specified directory and runs composer install.
 *
 * Usage:
 *   hordectl install --install-dir=/var/www/horde
 *
 * @author Ralf Lang <ralf.lang@ralf-lang.de>
 */
class Install implements Module, ModuleUsage
{
    use ModuleTrait;

    protected HordeCli $cli;
    protected Output $output;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->output = $dependencies->createOutput($this->cli);
        $this->_parser = $dependencies->getInstance(Parser::class);
        $this->_parser->allowInterspersedArgs = false;
    }

    public function getBaseOptions()
    {
        return [
            new Option(
                '--install-dir',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Directory to install Horde (required)',
                ]
            ),
            new Option(
                '--stability',
                [
                    'action' => 'store',
                    'type' => 'choice',
                    'choices' => ['dev', 'alpha', 'beta', 'rc', 'stable'],
                    'help' => 'Minimum stability for composer (dev, alpha, beta, rc, stable)',
                ]
            ),
        ];
    }

    /**
     * Handle the install command
     *
     * @param array $argv Command arguments
     * @return bool True if handled
     */
    public function handle(array $argv = []): bool
    {
        // Check if this is our command
        if (empty($argv) || $argv[0] !== 'install') {
            return false;
        }

        [$opts, $args] = $this->handleCommandline($argv);

        try {
            // Validate install-dir is provided
            $installDir = $opts->install_dir ?? null;
            if (!$installDir) {
                $this->showUsage();
                return true;
            }

            // Expand ~ to home directory
            if (str_starts_with($installDir, '~/')) {
                $installDir = $_SERVER['HOME'] . substr($installDir, 1);
            }

            // Check if directory exists and is not empty
            if (file_exists($installDir)) {
                $files = scandir($installDir);
                $files = array_diff($files, ['.', '..']);
                if (!empty($files)) {
                    $this->cli->writeln();
                    $this->output->warn("Install directory already exists and is not empty: $installDir");
                    $this->cli->writeln();
                    return true;
                }
            } else {
                // Create directory
                if (!mkdir($installDir, 0o755, true)) {
                    $this->cli->writeln();
                    $this->output->warn("Failed to create directory: $installDir");
                    $this->cli->writeln();
                    return true;
                }
                $this->output->ok("Created directory: $installDir");
            }

            // Get latest tag from horde/bundle
            $this->cli->writeln();
            $this->output->info('Fetching latest horde/bundle release...');

            $latestTag = $this->getLatestBundleTag();
            if (!$latestTag) {
                $this->cli->writeln();
                $this->output->warn('Could not determine latest horde/bundle version');
                $this->cli->writeln('Please check your network connection or try again later.');
                $this->cli->writeln();
                return true;
            }

            $this->cli->writeln("  Latest version: $latestTag");

            // Download URL for the tagged release
            $downloadUrl = "https://github.com/horde/bundle/archive/refs/tags/{$latestTag}.tar.gz";

            $this->cli->writeln();
            $this->output->info('Downloading horde/bundle...');
            $this->cli->writeln("  URL: $downloadUrl");

            // Download to temporary file
            $tmpFile = tempnam(sys_get_temp_dir(), 'horde_bundle_');
            if (!$this->downloadFile($downloadUrl, $tmpFile)) {
                @unlink($tmpFile);
                $this->cli->writeln();
                $this->output->warn('Failed to download horde/bundle');
                $this->cli->writeln("Could not download from: $downloadUrl");
                $this->cli->writeln('Please check your network connection or try again later.');
                $this->cli->writeln();
                return true;
            }

            $this->output->ok('Downloaded successfully');

            // Extract archive
            $this->cli->writeln();
            $this->output->info('Extracting archive...');

            if (!$this->extractTarGz($tmpFile, $installDir, $latestTag)) {
                @unlink($tmpFile);
                $this->cli->writeln();
                $this->output->warn('Failed to extract archive');
                $this->cli->writeln('The downloaded archive may be corrupted. Please try again.');
                $this->cli->writeln();
                return true;
            }

            @unlink($tmpFile);
            $this->output->ok('Extracted successfully');

            // Set minimum-stability if requested
            if (isset($opts->stability)) {
                $this->cli->writeln();
                $this->output->info("Setting minimum-stability to: {$opts->stability}");

                if (!$this->setComposerStability($installDir, $opts->stability)) {
                    $this->cli->writeln();
                    $this->output->warn('Failed to set composer minimum-stability');
                    $this->cli->writeln('You may need to set it manually in composer.json');
                    $this->cli->writeln();
                } else {
                    $this->output->ok('Composer stability configured');
                }
            }

            // Run composer install
            $this->cli->writeln();
            $this->output->info('Running composer install...');
            $this->cli->writeln("  Working directory: $installDir");

            if (!$this->runComposerInstall($installDir)) {
                $this->cli->writeln();
                $this->output->warn('Composer install failed');
                $this->cli->writeln('You may need to run composer install manually:');
                $this->cli->writeln("  cd $installDir && composer install");
                $this->cli->writeln();
                return true;
            }

            $this->output->ok('Composer install completed');

            // Success message
            $this->cli->writeln();
            $this->output->ok('Horde installation complete!');
            $this->cli->writeln();
            $this->cli->writeln("  Installation directory: $installDir");
            $this->cli->writeln("  Version: $latestTag");
            $this->cli->writeln();
            $this->cli->writeln('Next steps:');
            $this->cli->writeln('  1. Add this installation as a target:');
            $this->cli->writeln("     hordectl target add mysite --type=local --path=$installDir");
            $this->cli->writeln();
            $this->cli->writeln('  2. Activate the installation:');
            $this->cli->writeln('     hordectl activate');
            $this->cli->writeln();
            $this->cli->writeln('  3. Configure database:');
            $this->cli->writeln('     hordectl configure database');
            $this->cli->writeln();

            return true;
        } catch (RuntimeException $e) {
            $this->cli->writeln();
            $this->output->warn('Installation failed: ' . $e->getMessage());
            $this->cli->writeln();
            return false;
        }
    }

    /**
     * Show usage information when required parameters are missing
     */
    protected function showUsage(): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Usage: hordectl install --install-dir=<directory> [options]');
        $this->cli->writeln();
        $this->cli->writeln('Install Horde by downloading the latest tagged release of horde/bundle');
        $this->cli->writeln('and running composer install.');
        $this->cli->writeln();
        $this->cli->writeln('Required options:');
        $this->cli->writeln('  --install-dir=<directory>   Directory where Horde will be installed');
        $this->cli->writeln('                              (must not exist or be empty)');
        $this->cli->writeln();
        $this->cli->writeln('Optional:');
        $this->cli->writeln('  --stability=<level>         Composer minimum-stability');
        $this->cli->writeln('                              (dev, alpha, beta, rc, stable)');
        $this->cli->writeln();
        $this->cli->writeln('Examples:');
        $this->cli->writeln('  hordectl install --install-dir=/var/www/horde');
        $this->cli->writeln('  hordectl install --install-dir=~/horde-test --stability=dev');
        $this->cli->writeln();
        $this->cli->writeln('For more information, run: hordectl help install');
        $this->cli->writeln();
    }

    /**
     * Get latest tag from local horde/bundle repository
     *
     * @return string|null Latest tag or null if not found
     */
    private function getLatestBundleTag(): ?string
    {
        // First try local git repository
        $bundleRepo = $_SERVER['HOME'] . '/php/git/horde/bundle';
        if (is_dir($bundleRepo . '/.git')) {
            $output = [];
            $return = 0;
            exec("cd '$bundleRepo' && git fetch --tags 2>&1 && git tag --list | sort -V | tail -1", $output, $return);
            if ($return === 0 && !empty($output)) {
                return trim(end($output));
            }
        }

        // Fallback: use GitHub API
        $apiUrl = 'https://api.github.com/repos/horde/bundle/tags';
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: hordectl\r\n",
            ],
        ]);

        $response = @file_get_contents($apiUrl, false, $context);
        if ($response === false) {
            return null;
        }

        $tags = json_decode($response, true);
        if (!is_array($tags) || empty($tags)) {
            return null;
        }

        // Return first tag (most recent)
        return $tags[0]['name'] ?? null;
    }

    /**
     * Download file from URL
     *
     * @param string $url URL to download
     * @param string $destination Destination file path
     * @return bool True on success
     */
    private function downloadFile(string $url, string $destination): bool
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: hordectl\r\n",
                'follow_location' => 1,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);
        if ($data === false) {
            return false;
        }

        return file_put_contents($destination, $data) !== false;
    }

    /**
     * Extract tar.gz archive
     *
     * @param string $archiveFile Path to tar.gz file
     * @param string $destinationDir Destination directory
     * @param string $version Version tag (for stripping top directory)
     * @return bool True on success
     */
    private function extractTarGz(string $archiveFile, string $destinationDir, string $version): bool
    {
        // GitHub archives extract to bundle-{version}/ directory
        // We need to extract and move contents up one level
        $tmpExtract = sys_get_temp_dir() . '/horde_extract_' . uniqid();
        mkdir($tmpExtract, 0o755, true);

        $output = [];
        $return = 0;
        exec("tar -xzf " . escapeshellarg($archiveFile) . " -C " . escapeshellarg($tmpExtract) . " 2>&1", $output, $return);

        if ($return !== 0) {
            @rmdir($tmpExtract);
            return false;
        }

        // Move contents from bundle-{version}/ to destination
        $extractedDir = $tmpExtract . '/bundle-' . $version;
        if (!is_dir($extractedDir)) {
            @rmdir($tmpExtract);
            return false;
        }

        // Move all files from extracted directory to destination
        $files = scandir($extractedDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $source = $extractedDir . '/' . $file;
            $dest = $destinationDir . '/' . $file;
            if (!rename($source, $dest)) {
                return false;
            }
        }

        // Cleanup
        @rmdir($extractedDir);
        @rmdir($tmpExtract);

        return true;
    }

    /**
     * Run composer install in directory
     *
     * @param string $installDir Installation directory
     * @return bool True on success
     */
    private function runComposerInstall(string $installDir): bool
    {
        $composerBin = $this->findComposerBinary();
        if (!$composerBin) {
            $this->cli->writeln();
            $this->output->warn('Composer not found in PATH');
            $this->cli->writeln('Please run composer install manually:');
            $this->cli->writeln("  cd $installDir && composer install");
            return false;
        }

        $output = [];
        $return = 0;
        $cmd = "cd " . escapeshellarg($installDir) . " && $composerBin install --no-dev 2>&1";
        exec($cmd, $output, $return);

        if ($return !== 0) {
            $this->cli->writeln();
            $this->output->warn('Composer install failed:');
            foreach ($output as $line) {
                $this->cli->writeln("  $line");
            }
            return false;
        }

        return true;
    }

    /**
     * Set minimum-stability in composer.json
     *
     * @param string $installDir Installation directory
     * @param string $stability Stability level (dev, alpha, beta, rc, stable)
     * @return bool True on success
     */
    private function setComposerStability(string $installDir, string $stability): bool
    {
        $composerFile = $installDir . '/composer.json';
        if (!file_exists($composerFile)) {
            return false;
        }

        $composerData = json_decode(file_get_contents($composerFile), true);
        if (!is_array($composerData)) {
            return false;
        }

        $composerData['minimum-stability'] = $stability;

        $json = json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return file_put_contents($composerFile, $json . "\n") !== false;
    }

    /**
     * Find composer binary
     *
     * @return string|null Path to composer binary or null if not found
     */
    private function findComposerBinary(): ?string
    {
        // Check common locations
        $candidates = [
            '/usr/local/bin/composer',
            '/usr/bin/composer',
        ];

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        // Try to find in PATH
        $which = trim((string) shell_exec('which composer 2>/dev/null'));
        if ($which && is_executable($which)) {
            return $which;
        }

        return null;
    }

    public function getUsage()
    {
        return 'install --install-dir=<directory>

Install Horde by downloading the latest tagged release of horde/bundle
and running composer install.

This command will:
  1. Create the installation directory if it doesn\'t exist
  2. Download the latest tagged release from GitHub (horde/bundle)
  3. Extract the archive
  4. Run composer install

OPTIONS
    --install-dir=<directory>
        Directory where Horde will be installed (required)
        The directory must not exist or be empty

EXAMPLES
    # Install to /var/www/horde
    hordectl install --install-dir=/var/www/horde

    # Install to user directory
    hordectl install --install-dir=~/horde-test
';
    }

    public function getSummary()
    {
        return 'Install Horde by downloading latest horde/bundle';
    }
}
