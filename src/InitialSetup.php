<?php

namespace Horde\Hordectl;

/**
 * Handles initial setup and configuration discovery
 *
 * This class runs BEFORE bootstrapping Horde, using only hordectl's autoloader
 */
class InitialSetup
{
    private ConfigManager $configManager;
    private HordeInstallationFinder $finder;
    private bool $configChanged = false;

    public function __construct()
    {
        $this->configManager = new ConfigManager();

        // Create environment from actual env + config
        $env = new Environment(array_merge(
            getenv(),
            $this->configManager->getAll()
        ));

        $this->finder = new HordeInstallationFinder($env);
    }

    /**
     * Run initial setup - discover installation and save config
     *
     * @return bool True if setup is complete, false if user needs to configure
     */
    public function ensureConfigured(): bool
    {
        // Check if we already have a valid HORDE_BASE configured
        if ($this->configManager->has('HORDE_BASE')) {
            $candidate = $this->configManager->get('HORDE_BASE');
            if ($this->isValidHordeBase($candidate)) {
                // Configuration is valid - ready to proceed
                return true;
            } else {
                // Configured path is no longer valid
                $this->reportInvalidPath($candidate);
                $this->configChanged = true;
            }
        } else {
            // No configuration exists - this is first run
            $this->configChanged = true;
        }

        // Try to discover installation
        try {
            $hordeBase = $this->finder->find();

            // Found it! Save to config
            $this->configManager->set('HORDE_BASE', $hordeBase);

            // Also save other discovered paths for convenience
            $this->discoverAndSaveRelatedPaths($hordeBase);

            $this->configManager->save();

            $this->reportSuccess($hordeBase);

            return true;

        } catch (HordeNotFoundException $e) {
            // Could not find installation
            $this->reportNotFound();
            return false;
        }
    }

    /**
     * Check if a path is a valid Horde base directory
     */
    private function isValidHordeBase(string $path): bool
    {
        return file_exists($path . '/lib/Application.php')
            || file_exists($path . '/src/Application.php');
    }

    /**
     * Discover related paths from HORDE_BASE and save them
     */
    private function discoverAndSaveRelatedPaths(string $hordeBase): void
    {
        // Try to determine HORDE_INSTALL_DIR (bundle root)
        // If HORDE_BASE is in vendor/horde/horde, go up 3 levels
        if (preg_match('#^(.+)/vendor/horde/horde$#', $hordeBase, $matches)) {
            $installDir = $matches[1];
            if (file_exists($installDir . '/composer.json')) {
                $this->configManager->set('HORDE_INSTALL_DIR', $installDir);
            }
        }

        // Try to determine HORDE_GIT_DIR if running from git checkout
        // If HORDE_BASE is in git/horde/base, go up 1 level
        if (preg_match('#^(.+)/base$#', $hordeBase, $matches)) {
            $gitDir = $matches[1];
            // Check if this looks like a git checkout (has multiple .git dirs)
            if (is_dir($gitDir) && glob($gitDir . '/*/.git')) {
                $this->configManager->set('HORDE_GIT_DIR', $gitDir);
            }
        }
    }

    /**
     * Report that configured path is no longer valid
     */
    private function reportInvalidPath(string $path): void
    {
        fwrite(STDERR, "\n");
        fwrite(STDERR, "Warning: Previously configured Horde installation is no longer valid:\n");
        fwrite(STDERR, "  Path: $path\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "Searching for a valid installation...\n");
        fwrite(STDERR, "\n");
    }

    /**
     * Report successful discovery
     */
    private function reportSuccess(string $hordeBase): void
    {
        $configPath = $this->configManager->getConfigPath();

        fwrite(STDOUT, "\n");
        fwrite(STDOUT, "✓ Horde installation discovered and configured!\n");
        fwrite(STDOUT, "\n");
        fwrite(STDOUT, "  Horde Base: $hordeBase\n");

        if ($this->configManager->has('HORDE_INSTALL_DIR')) {
            fwrite(STDOUT, "  Installation: " . $this->configManager->get('HORDE_INSTALL_DIR') . "\n");
        }

        if ($this->configManager->has('HORDE_GIT_DIR')) {
            fwrite(STDOUT, "  Git Checkout: " . $this->configManager->get('HORDE_GIT_DIR') . "\n");
        }

        fwrite(STDOUT, "\n");
        fwrite(STDOUT, "  Configuration saved to: $configPath\n");
        fwrite(STDOUT, "\n");

        // Check if we can actually use this installation
        if (!$this->canBootstrapSafely($hordeBase)) {
            fwrite(STDOUT, "IMPORTANT: Autoloader conflict detected!\n");
            fwrite(STDOUT, "\n");
            fwrite(STDOUT, "hordectl cannot currently run from git checkout against a different\n");
            fwrite(STDOUT, "Horde installation due to autoloader conflicts.\n");
            fwrite(STDOUT, "\n");
            fwrite(STDOUT, "To use hordectl with this installation, run it from the installation:\n");

            if ($this->configManager->has('HORDE_INSTALL_DIR')) {
                $installDir = $this->configManager->get('HORDE_INSTALL_DIR');
                fwrite(STDOUT, "  cd $installDir\n");
                fwrite(STDOUT, "  ./vendor/bin/hordectl\n");
            } else {
                fwrite(STDOUT, "  cd /path/to/installation\n");
                fwrite(STDOUT, "  ./vendor/bin/hordectl\n");
            }

            fwrite(STDOUT, "\n");
            fwrite(STDOUT, "Future versions will support running from git checkout via subprocess API.\n");
            fwrite(STDOUT, "\n");
        } else {
            fwrite(STDOUT, "You can now run hordectl commands.\n");
            fwrite(STDOUT, "\n");
        }
    }

    /**
     * Check if we can safely bootstrap the discovered Horde installation
     *
     * Returns false if there would be autoloader conflicts
     */
    private function canBootstrapSafely(string $hordeBase): bool
    {
        // If we're using Composer's bin proxy autoloader, we're already using
        // the bundle's autoloader, so no conflict
        if (isset($GLOBALS['_composer_autoload_path'])) {
            return true;
        }

        // Check if we're running hordectl from git checkout
        $hordectlRoot = dirname(__DIR__);

        // If hordectl has its own vendor directory, we might conflict
        if (file_exists($hordectlRoot . '/vendor/autoload.php')) {
            // Check if the target Horde is different from hordectl's vendor
            $hordectlVendor = realpath($hordectlRoot . '/vendor');
            $targetVendor = realpath(dirname($hordeBase, 2)); // Up from horde/horde to vendor

            if ($hordectlVendor !== $targetVendor) {
                // Different vendor directories = autoloader conflict
                return false;
            }
        }

        return true;
    }

    /**
     * Report that no installation was found
     */
    private function reportNotFound(): void
    {
        fwrite(STDERR, "\n");
        fwrite(STDERR, "Error: Could not find Horde installation.\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "hordectl searched for Horde in:\n");
        fwrite(STDERR, "  • Current directory: " . getcwd() . "/vendor/horde/horde\n");
        fwrite(STDERR, "  • System location: /srv/www/horde-dev/vendor/horde/horde\n");
        fwrite(STDERR, "  • User location: ~/www/horde-dev/vendor/horde/horde\n");
        fwrite(STDERR, "  • Environment variables: \$HORDE_BASE, \$HORDE_GIT_DIR\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "To configure manually, you can:\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "  1. Set environment variable:\n");
        fwrite(STDERR, "       export HORDE_BASE=/path/to/installation/vendor/horde/horde\n");
        fwrite(STDERR, "       hordectl\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "  2. Edit config file directly:\n");
        fwrite(STDERR, "       " . $this->configManager->getConfigPath() . "\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "     Add this content:\n");
        fwrite(STDERR, "       <?php\n");
        fwrite(STDERR, "       return [\n");
        fwrite(STDERR, "           'HORDE_BASE' => '/path/to/installation/vendor/horde/horde',\n");
        fwrite(STDERR, "       ];\n");
        fwrite(STDERR, "\n");
        fwrite(STDERR, "  3. Run hordectl from within your Horde installation directory\n");
        fwrite(STDERR, "\n");
    }

    /**
     * Get the discovered HORDE_BASE path
     */
    public function getHordeBase(): ?string
    {
        return $this->configManager->get('HORDE_BASE');
    }

    /**
     * Check if this is first run or config needs update
     */
    public function needsConfiguration(): bool
    {
        return !$this->configManager->has('HORDE_BASE') || $this->configChanged;
    }
}
