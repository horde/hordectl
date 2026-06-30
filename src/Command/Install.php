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
use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\Exception\TargetAlreadyExistsException;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\Target;
use Horde\Hordectl\TargetResolver;
use Horde\Hordectl\TargetType;
use Horde\Http\Client\Curl;
use Horde\Http\Client\Options as HttpClientOptions;
use Horde\Http\RequestFactory;
use Horde\Http\ResponseFactory;
use Horde\Http\StreamFactory;
use Horde\Injector\Injector;
use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
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
    protected ClientInterface $httpClient;
    protected RequestFactoryInterface $requestFactory;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->output = $dependencies->createOutput($this->cli);
        $this->_parser = $dependencies->getInstance(Parser::class);
        $this->_parser->allowInterspersedArgs = false;

        // Initialize HTTP client for downloads
        $clientOptions = new HttpClientOptions([
            'timeout' => 120, // Longer timeout for large downloads
        ]);
        $responseFactory = new ResponseFactory();
        $streamFactory = new StreamFactory();
        $this->httpClient = new Curl($responseFactory, $streamFactory, $clientOptions);
        $this->requestFactory = new RequestFactory();
    }

    public function getBaseOptions(): array
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
                '--bundle-version',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Bundle version to install (tag, branch, or commit hash). Default: latest tag',
                ]
            ),
            new Option(
                '--source',
                [
                    'action' => 'store',
                    'type' => 'string',
                    'help' => 'Bundle source (GitHub URL or local path). Default: https://github.com/horde/bundle',
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

            // Parse source
            $source = $opts->source ?? 'https://github.com/horde/bundle';
            $parsedSource = $this->parseSourceUrl($source);

            if (!$parsedSource) {
                $this->cli->writeln();
                $this->output->warn("Invalid source: $source");
                $this->cli->writeln('Source must be a GitHub URL or valid local path');
                $this->cli->writeln();
                return true;
            }

            // Determine version
            $version = $opts->bundle_version ?? null;
            if (!$version) {
                $this->cli->writeln();
                $this->output->info('Fetching latest bundle version...');

                $version = $this->getLatestBundleTag($source);
                if (!$version) {
                    $this->cli->writeln();
                    $this->output->warn('Could not determine latest version');
                    $this->cli->writeln('Please specify a version with --bundle-version');
                    $this->cli->writeln();
                    return true;
                }
                $this->cli->writeln("  Latest version: $version");
            }

            $this->cli->writeln();
            $this->output->info('Installing Horde bundle...');
            $this->cli->writeln("  Source: $source");
            $this->cli->writeln("  Version: $version");

            // Handle based on source type
            if ($parsedSource['type'] === 'local') {
                // Copy from local repository
                $this->cli->writeln();
                $this->output->info('Copying from local repository...');

                if (!$this->copyLocalBundle($parsedSource['path'], $installDir, $version)) {
                    $this->cli->writeln();
                    $this->output->warn('Failed to copy from local repository');
                    $this->cli->writeln();
                    return true;
                }

                $this->output->ok('Copied successfully');
            } else {
                // Download from GitHub
                $downloadUrl = $this->buildDownloadUrl($parsedSource, $version);
                $archiveDirName = $this->getArchiveDirectoryName($parsedSource['repo'], $version);

                $this->cli->writeln();
                $this->output->info('Downloading bundle...');
                $this->cli->writeln("  URL: $downloadUrl");

                // Download to temporary file
                $tmpFile = tempnam(sys_get_temp_dir(), 'horde_bundle_');
                if (!$this->downloadFile($downloadUrl, $tmpFile)) {
                    @unlink($tmpFile);
                    $this->cli->writeln();
                    $this->output->warn('Failed to download bundle');
                    $this->cli->writeln("Could not download from: $downloadUrl");
                    $this->cli->writeln();
                    $this->cli->writeln('This may be because:');
                    $this->cli->writeln('  - The version/ref does not exist');
                    $this->cli->writeln('  - The repository is private or does not exist');
                    $this->cli->writeln('  - Network connection issues');
                    $this->cli->writeln();
                    $this->cli->writeln('Please verify:');
                    $this->cli->writeln("  - Version '$version' exists in the repository");
                    $this->cli->writeln('  - The source URL is correct');
                    $this->cli->writeln();
                    return true;
                }

                $this->output->ok('Downloaded successfully');

                // Extract archive
                $this->cli->writeln();
                $this->output->info('Extracting archive...');

                // Check if URL is for a zip file
                $isZip = str_ends_with($downloadUrl, '.zip');

                if (!$this->extractArchive($tmpFile, $installDir, $archiveDirName, $isZip)) {
                    @unlink($tmpFile);
                    $this->cli->writeln();
                    $this->output->warn('Failed to extract archive');
                    $this->cli->writeln('The downloaded archive may be invalid. Please check the version exists.');
                    $this->cli->writeln();
                    return true;
                }

                @unlink($tmpFile);
                $this->output->ok('Extracted successfully');
            }

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

            // Auto-create local target for this installation
            $this->createLocalTarget($installDir);

            $this->cli->writeln();
            $this->output->ok('Horde installation complete!');
            $this->cli->writeln();
            $this->cli->writeln("  Installation directory: $installDir");
            $this->cli->writeln("  Source: $source");
            $this->cli->writeln("  Version: $version");
            $this->cli->writeln();
            $this->cli->writeln('Next steps:');
            $this->cli->writeln('  1. Activate the installation:');
            $this->cli->writeln('     hordectl activate');
            $this->cli->writeln();
            $this->cli->writeln('  2. Configure database:');
            $this->cli->writeln('     hordectl configure database');
            $this->cli->writeln();
            $this->cli->writeln('  3. Generate admin secret:');
            $this->cli->writeln('     hordectl secret generate');
            $this->cli->writeln();

            return true;
        } catch (RuntimeException $e) {
            $this->cli->writeln();
            $this->output->warn('Installation failed: ' . $e->getMessage());
            $this->cli->writeln();
            return false;
        }
    }

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
        $this->cli->writeln('  --bundle-version=<version>  Bundle version to install (tag, branch, or commit)');
        $this->cli->writeln('                              Examples: 1.0.0-beta1, FRAMEWORK_6_0, abc1234');
        $this->cli->writeln('                              Default: latest tag');
        $this->cli->writeln();
        $this->cli->writeln('  --source=<url>              Bundle source (GitHub URL or local path)');
        $this->cli->writeln('                              Default: https://github.com/horde/bundle');
        $this->cli->writeln('                              Examples:');
        $this->cli->writeln('                                https://github.com/myorg/bundle');
        $this->cli->writeln('                                ~/php/git/horde/bundle');
        $this->cli->writeln();
        $this->cli->writeln('  --stability=<level>         Composer minimum-stability');
        $this->cli->writeln('                              (dev, alpha, beta, rc, stable)');
        $this->cli->writeln();
        $this->cli->writeln('Examples:');
        $this->cli->writeln('  hordectl install --install-dir=/var/www/horde');
        $this->cli->writeln('  hordectl install --install-dir=~/horde-test --stability=dev');
        $this->cli->writeln('  hordectl install --install-dir=/tmp/test --bundle-version=1.0.0-beta1');
        $this->cli->writeln('  hordectl install --install-dir=/tmp/test --source=~/php/git/horde/bundle');
        $this->cli->writeln();
        $this->cli->writeln('For more information, run: hordectl help install');
        $this->cli->writeln();
    }

    /**
     * Get latest tag from bundle repository
     *
     * @param string $source Source URL or path
     * @return string|null Latest tag or null if not found
     */
    private function getLatestBundleTag(string $source = 'https://github.com/horde/bundle'): ?string
    {
        // Parse source
        $parsed = $this->parseSourceUrl($source);
        if (!$parsed) {
            return null;
        }

        // For local repositories, try git commands
        if ($parsed['type'] === 'local') {
            $output = [];
            $return = 0;
            exec("cd " . escapeshellarg($parsed['path']) . " && git tag --list | sort -V | tail -1 2>&1", $output, $return);
            if ($return === 0 && !empty($output)) {
                return trim(end($output));
            }
            return null;
        }

        // For GitHub, use API
        if ($parsed['type'] === 'github') {
            $apiUrl = sprintf('https://api.github.com/repos/%s/%s/tags', $parsed['user'], $parsed['repo']);

            try {
                $request = $this->requestFactory->createRequest('GET', $apiUrl)
                    ->withHeader('User-Agent', 'hordectl');
                $response = $this->httpClient->sendRequest($request);

                if ($response->getStatusCode() !== 200) {
                    return null;
                }

                $tags = json_decode((string) $response->getBody(), true);
                if (!is_array($tags) || empty($tags)) {
                    return null;
                }

                // Return first tag (most recent)
                return $tags[0]['name'] ?? null;
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
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
        try {
            $request = $this->requestFactory->createRequest('GET', $url)
                ->withHeader('User-Agent', 'hordectl');
            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                return false;
            }

            return file_put_contents($destination, (string) $response->getBody()) !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Extract archive (tar.gz or zip)
     *
     * @param string $archiveFile Path to archive file
     * @param string $destinationDir Destination directory
     * @param string $archiveDirName Expected directory name in archive
     * @param bool $isZip Whether the archive is a zip file
     * @return bool True on success
     */
    private function extractArchive(
        string $archiveFile,
        string $destinationDir,
        string $archiveDirName,
        bool $isZip = false
    ): bool {
        // GitHub archives extract to {repo}-{version}/ directory
        // We need to extract and move contents up one level
        $tmpExtract = sys_get_temp_dir() . '/horde_extract_' . uniqid();
        mkdir($tmpExtract, 0o755, true);

        // Extract based on type
        if ($isZip) {
            // Use PHP's zip extension if available, fallback to unzip command
            if (class_exists('ZipArchive')) {
                $zip = new \ZipArchive();
                if ($zip->open($archiveFile) !== true) {
                    @rmdir($tmpExtract);
                    return false;
                }
                if (!$zip->extractTo($tmpExtract)) {
                    $zip->close();
                    @rmdir($tmpExtract);
                    return false;
                }
                $zip->close();
            } else {
                // Fallback to unzip command
                $output = [];
                $return = 0;
                exec("unzip -q " . escapeshellarg($archiveFile) . " -d " . escapeshellarg($tmpExtract) . " 2>&1", $output, $return);
                if ($return !== 0) {
                    @rmdir($tmpExtract);
                    return false;
                }
            }
        } else {
            // tar.gz
            $output = [];
            $return = 0;
            exec("tar -xzf " . escapeshellarg($archiveFile) . " -C " . escapeshellarg($tmpExtract) . " 2>&1", $output, $return);
            if ($return !== 0) {
                @rmdir($tmpExtract);
                return false;
            }
        }

        // Move contents from extracted directory to destination
        $extractedDir = $tmpExtract . '/' . $archiveDirName;
        if (!is_dir($extractedDir)) {
            // Fallback: the predicted directory name did not match what
            // the archive actually contained. This can happen when the
            // archive comes from a non-GitHub source whose naming rules
            // differ, or when GitHub changes its naming convention in a
            // way the predictor does not yet model. Look for any single
            // top-level directory and use it. If the layout is not the
            // expected single-root shape, give up rather than guess.
            $discovered = $this->findSingleTopLevelDirectory($tmpExtract);
            if ($discovered === null) {
                @rmdir($tmpExtract);
                return false;
            }
            $extractedDir = $discovered;
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
     * Locate a single top-level directory inside an extracted archive.
     *
     * Used by {@see extractArchive()} as a fallback when the predicted
     * archive-directory name does not exist. Returns the absolute path
     * to the discovered directory, or `null` if the archive does not
     * have the conventional single-root-directory shape (e.g. files at
     * the top level, multiple top-level directories, or an empty
     * archive).
     *
     * Pure function (filesystem-driven): does not modify the directory
     * it inspects.
     *
     * @param string $tmpExtract Path to the directory the archive was
     *                           extracted into.
     * @return string|null Absolute path to the single top-level
     *                    directory, or null when the shape is not
     *                    single-root.
     */
    private function findSingleTopLevelDirectory(string $tmpExtract): ?string
    {
        $entries = scandir($tmpExtract);
        if ($entries === false) {
            return null;
        }

        $topLevelDirs = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $tmpExtract . '/' . $entry;
            if (!is_dir($path)) {
                // A file at the top level is incompatible with the
                // single-root shape we expect from a GitHub archive.
                return null;
            }
            $topLevelDirs[] = $path;
        }

        if (count($topLevelDirs) !== 1) {
            return null;
        }

        return $topLevelDirs[0];
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
        $cmd = "cd " . escapeshellarg($installDir) . " && COMPOSER_ALLOW_SUPERUSER=1 $composerBin install 2>&1";
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
     * Parse source URL into components
     *
     * @param string $source Source URL or path
     * @return array|null ['type' => 'github'|'local', 'user' => string, 'repo' => string] or null if invalid
     */
    private function parseSourceUrl(string $source): ?array
    {
        // Check if it's a GitHub URL
        if (preg_match('#^https?://github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$#', $source, $matches)) {
            return [
                'type' => 'github',
                'user' => $matches[1],
                'repo' => $matches[2],
            ];
        }

        // Check if it's a local path
        if (!str_starts_with($source, 'http://') && !str_starts_with($source, 'https://')) {
            // Expand ~ and relative paths
            if (str_starts_with($source, '~/')) {
                $source = $_SERVER['HOME'] . substr($source, 1);
            } elseif (!str_starts_with($source, '/')) {
                $source = getcwd() . '/' . $source;
            }

            $source = realpath($source);
            if ($source && is_dir($source)) {
                return [
                    'type' => 'local',
                    'path' => $source,
                ];
            }
            return null; // Invalid local path
        }

        // Unsupported URL format
        return null;
    }

    /**
     * Build download URL for bundle
     *
     * @param array $source Parsed source from parseSourceUrl()
     * @param string $version Version/ref to download
     * @return string Download URL
     */
    private function buildDownloadUrl(array $source, string $version): string
    {
        if ($source['type'] === 'github') {
            // GitHub archive URL: https://github.com/[user]/[repo]/archive/[ref].zip
            return sprintf(
                'https://github.com/%s/%s/archive/%s.zip',
                $source['user'],
                $source['repo'],
                $version
            );
        }

        throw new RuntimeException('Cannot build download URL for non-GitHub source');
    }

    /**
     * Get directory name from extracted archive
     *
     * GitHub archives extract to: {repo}-{ref}/
     *
     * Two rules to mirror:
     *
     *  1. The ref is sanitized — slashes become dashes (so `feat/foo`
     *     in the URL produces `bundle-feat-foo/` on disk).
     *
     *  2. A leading 'v' is stripped from semver-like tags. GitHub keeps
     *     the 'v' in the download URL but drops it in the directory
     *     name inside the archive: `v1.1.1RC1.zip` extracts to
     *     `bundle-1.1.1RC1/`. The heuristic matches `^v\d` so that
     *     tags like `vendor-branch` (which happen to start with 'v'
     *     but are not semver tags) are NOT stripped. See issue #13.
     *
     * @param string $repo Repository name
     * @param string $version Version/ref downloaded
     * @return string Expected directory name
     */
    private function getArchiveDirectoryName(string $repo, string $version): string
    {
        $sanitizedRef = str_replace('/', '-', $version);

        if (preg_match('/^v\d/', $sanitizedRef) === 1) {
            $sanitizedRef = substr($sanitizedRef, 1);
        }

        return "{$repo}-{$sanitizedRef}";
    }

    /**
     * Copy bundle from local repository
     *
     * @param string $localPath Path to local git repository
     * @param string $installDir Destination directory
     * @param string|null $version Optional version/ref to checkout
     * @return bool True on success
     */
    private function copyLocalBundle(
        string $localPath,
        string $installDir,
        ?string $version
    ): bool {
        // Use git archive if .git directory exists and version specified
        if (is_dir($localPath . '/.git') && $version) {
            $output = [];
            $return = 0;

            // Create temporary tar file
            $tmpFile = tempnam(sys_get_temp_dir(), 'horde_local_');
            $tarFile = $tmpFile . '.tar';
            rename($tmpFile, $tarFile);

            // Use git archive to export specific ref
            $cmd = sprintf(
                "cd %s && git archive --format=tar --output=%s %s 2>&1",
                escapeshellarg($localPath),
                escapeshellarg($tarFile),
                escapeshellarg($version)
            );
            exec($cmd, $output, $return);

            if ($return !== 0) {
                @unlink($tarFile);
                return false;
            }

            // Extract tar to destination
            exec("tar -xf " . escapeshellarg($tarFile) . " -C " . escapeshellarg($installDir) . " 2>&1", $output, $return);
            @unlink($tarFile);

            return $return === 0;
        }

        // Otherwise, just copy files (excluding .git)
        $output = [];
        $return = 0;
        exec("rsync -a --exclude='.git' " . escapeshellarg($localPath . '/') . " " . escapeshellarg($installDir . '/') . " 2>&1", $output, $return);

        if ($return !== 0) {
            // Fallback to cp if rsync not available
            exec("cp -r " . escapeshellarg($localPath) . "/. " . escapeshellarg($installDir) . "/ 2>&1", $output, $return);
            // Remove .git directory if copied
            if ($return === 0 && is_dir($installDir . '/.git')) {
                exec("rm -rf " . escapeshellarg($installDir . '/.git') . " 2>&1");
            }
        }

        return $return === 0;
    }

    /**
     * Create local target for the installed Horde
     *
     * @param string $installDir Installation directory path
     */
    private function createLocalTarget(string $installDir): void
    {
        $this->cli->writeln();
        $this->output->info('Creating local target...');

        // Generate target name from install directory
        $targetName = basename($installDir);

        $config = new ConfigManager();
        $resolver = new TargetResolver();

        // Check if target already exists
        try {
            $existing = $resolver->getTarget($config, $targetName);
            $this->cli->writeln("Target '{$targetName}' already exists, skipping creation.");
            $this->cli->writeln("Run: hordectl target use {$targetName}");
            return;
        } catch (TargetAlreadyExistsException $e) {
            // Target exists, skip
            $this->cli->writeln("Target '{$targetName}' already exists, skipping creation.");
            return;
        } catch (\Exception $e) {
            // Target doesn't exist, continue to create it
        }

        // Create new local target (no endpoint yet - Horde not configured)
        $target = new Target(
            name: $targetName,
            type: TargetType::Local,
            hordeBase: null,
            hordeInstallDir: $installDir,
            endpoint: null,
            adminSecret: null,
            verifySsl: true,
            description: "Auto-created by install command",
            autoDetected: false,
            fromEnv: false
        );

        $resolver->saveTarget($config, $target);
        $resolver->setCurrentTarget($config, $targetName);

        $this->output->ok("Target '{$targetName}' created and activated");
        $this->cli->writeln("  Type: local");
        $this->cli->writeln("  Path: {$installDir}");
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

    public function getUsage(): string
    {
        return 'install --install-dir=<directory> [options]

Install Horde by downloading bundle from GitHub or copying from a local source,
then running composer install.

This command will:
  1. Create the installation directory if it doesn\'t exist
  2. Download or copy the bundle (default: latest tag from GitHub horde/bundle)
  3. Extract the archive
  4. Run composer install
  5. Create a local target for the installation

OPTIONS
    --install-dir=<directory>
        Directory where Horde will be installed (required)
        The directory must not exist or be empty

    --bundle-version=<version>
        Bundle version to install (tag, branch, or commit hash)
        Examples: 1.0.0-beta1, FRAMEWORK_6_0, abc123def456
        Default: latest tag

    --source=<url|path>
        Bundle source (GitHub URL or local path)
        Default: https://github.com/horde/bundle
        Examples:
          https://github.com/myorg/bundle
          ~/php/git/horde/bundle
          /home/user/projects/bundle

    --stability=<level>
        Composer minimum-stability (dev, alpha, beta, rc, stable)
        Use "dev" when installing development branches

EXAMPLES
    # Install latest version (default)
    hordectl install --install-dir=/var/www/horde

    # Install specific tagged version
    hordectl install --install-dir=/var/www/horde --bundle-version=1.0.0-beta1

    # Install development branch
    hordectl install --install-dir=/var/www/horde-dev \\
        --bundle-version=FRAMEWORK_6_0 --stability=dev

    # Install specific commit
    hordectl install --install-dir=/tmp/test \\
        --bundle-version=abc123def456789

    # Install from local repository
    hordectl install --install-dir=/tmp/test \\
        --source=~/php/git/horde/bundle

    # Install from fork
    hordectl install --install-dir=/tmp/test \\
        --source=https://github.com/myorg/bundle \\
        --bundle-version=custom-feature

    # Install from local repo with specific branch
    hordectl install --install-dir=/tmp/test \\
        --source=~/php/git/horde/bundle \\
        --bundle-version=FRAMEWORK_6_0
';
    }

    public function getSummary(): string
    {
        return 'Install Horde by downloading latest horde/bundle';
    }
}
