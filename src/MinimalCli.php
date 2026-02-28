<?php
namespace Horde\Hordectl;

use Horde\Argv\Parser;
use Horde\Argv\IndentedHelpFormatter;

/**
 * Minimal CLI for hordectl when Horde bootstrap fails
 *
 * Provides basic help and configuration commands without requiring
 * a fully configured Horde installation
 */
class MinimalCli
{
    private ConfigManager $config;
    private Parser $parser;

    public function __construct(ConfigManager $config)
    {
        $this->config = $config;
        $this->setupParser();
    }

    private function setupParser(): void
    {
        $this->parser = new Parser([
            'usage' => '%prog [options] command',
            'description' => 'Horde Control Tool (Minimal Mode - Horde not fully configured)',
            'epilog' => 'For more information, visit https://www.horde.org/',
        ]);

        $this->parser->addOption(
            '-v', '--verbose',
            [
                'action' => 'store_true',
                'help' => 'Enable verbose output',
            ]
        );

        $this->parser->addOption(
            '--version',
            [
                'action' => 'store_true',
                'help' => 'Show version information',
            ]
        );
    }

    public function run(array $argv): int
    {
        // Remove script name
        array_shift($argv);

        // Check for reconfigure command early (before parser tries to parse its options)
        if (count($argv) >= 1 && ($argv[0] === 'reconfigure' || $argv[0] === 'install')) {
            // Handle reconfigure --help specially
            if (count($argv) >= 2 && ($argv[1] === '--help' || $argv[1] === '-h')) {
                $this->showReconfigureHelp();
                return 0;
            }
            // Pass all args to reconfigure command (it handles its own options)
            return $this->reconfigureCommand(array_slice($argv, 1));
        }

        // Parse options for other commands
        list($options, $args) = $this->parser->parseArgs($argv);

        // Handle --version
        if ($options->version) {
            $this->showVersion();
            return 0;
        }

        // Get command
        $command = $args[0] ?? 'help';

        // Route to command
        switch ($command) {
            case 'help':
                $this->showHelp();
                return 0;

            case 'config':
                return $this->configCommand(array_slice($args, 1));

            case 'status':
                return $this->statusCommand();

            case 'bootstrap-check':
                return $this->bootstrapCheckCommand();

            default:
                fwrite(STDERR, "Error: Unknown command '$command'\n\n");
                fwrite(STDERR, "Horde is not fully configured. Available commands:\n");
                fwrite(STDERR, "  help              Show this help\n");
                fwrite(STDERR, "  config            Show or edit configuration\n");
                fwrite(STDERR, "  status            Check installation status\n");
                fwrite(STDERR, "  bootstrap-check   Test if Horde can bootstrap\n");
                fwrite(STDERR, "  reconfigure       Run installer to configure Horde\n");
                fwrite(STDERR, "\n");
                return 1;
        }
    }

    private function showVersion(): void
    {
        echo "hordectl (Minimal Mode)\n";
        echo "Part of Horde Framework 6\n";
        echo "\n";
        echo "Running in minimal mode because Horde bootstrap failed.\n";
    }

    private function showHelp(): void
    {
        echo "\n";
        echo "Hordectl - Horde Control Tool (Minimal Mode)\n";
        echo "============================================\n";
        echo "\n";
        echo "Horde is not fully configured, so hordectl is running in minimal mode.\n";
        echo "Only basic configuration commands are available.\n";
        echo "\n";
        echo "Available Commands:\n";
        echo "\n";
        echo "  help              Show this help message\n";
        echo "  config            Show or edit hordectl configuration\n";
        echo "  status            Check Horde installation status\n";
        echo "  bootstrap-check   Test if Horde can bootstrap\n";
        echo "  reconfigure       Run installer to configure Horde\n";
        echo "                    (aliases: install)\n";
        echo "\n";
        echo "Configuration Commands:\n";
        echo "\n";
        echo "  hordectl config                  Show current configuration\n";
        echo "  hordectl config show             Show current configuration\n";
        echo "  hordectl config set KEY VALUE    Set configuration value\n";
        echo "  hordectl config unset KEY        Remove configuration value\n";
        echo "  hordectl config path             Show config file path\n";
        echo "\n";
        echo "Examples:\n";
        echo "\n";
        echo "  # Show current configuration\n";
        echo "  hordectl config show\n";
        echo "\n";
        echo "  # Set Horde base directory\n";
        echo "  hordectl config set HORDE_BASE /srv/www/horde-dev/vendor/horde/horde\n";
        echo "\n";
        echo "  # Check installation status\n";
        echo "  hordectl status\n";
        echo "\n";
        echo "Reconfigure Command:\n";
        echo "\n";
        echo "  hordectl reconfigure [options]    Run Horde installer plugin to configure installation\n";
        echo "\n";
        echo "  Options:\n";
        echo "    --mode <mode>       Installation mode: symlink, proxy, copy (default: proxy)\n";
        echo "    --webroot <path>    Web root path (default: /)\n";
        echo "    --force             Delete and rewrite normally untouched files\n";
        echo "    --help              Show reconfigure help\n";
        echo "\n";
        echo "  Examples:\n";
        echo "    hordectl reconfigure\n";
        echo "    hordectl reconfigure --mode symlink --webroot /horde\n";
        echo "    hordectl reconfigure --force\n";
        echo "\n";
        echo "Why Minimal Mode?\n";
        echo "\n";
        echo "Horde bootstrap failed, likely because:\n";
        echo "  • Horde is not fully configured (missing config files)\n";
        echo "  • Database is not set up\n";
        echo "  • Configuration has errors\n";
        echo "\n";
        echo "Use 'hordectl status' to diagnose the issue.\n";
        echo "\n";
    }

    private function configCommand(array $args): int
    {
        $subcommand = $args[0] ?? 'show';

        switch ($subcommand) {
            case 'show':
                return $this->configShow();

            case 'set':
                if (count($args) < 3) {
                    fwrite(STDERR, "Error: 'config set' requires KEY and VALUE\n");
                    fwrite(STDERR, "Usage: hordectl config set KEY VALUE\n");
                    return 1;
                }
                return $this->configSet($args[1], $args[2]);

            case 'unset':
                if (count($args) < 2) {
                    fwrite(STDERR, "Error: 'config unset' requires KEY\n");
                    fwrite(STDERR, "Usage: hordectl config unset KEY\n");
                    return 1;
                }
                return $this->configUnset($args[1]);

            case 'path':
                echo $this->config->getConfigPath() . "\n";
                return 0;

            default:
                fwrite(STDERR, "Error: Unknown config subcommand '$subcommand'\n");
                fwrite(STDERR, "Available: show, set, unset, path\n");
                return 1;
        }
    }

    private function configShow(): int
    {
        $config = $this->config->getAll();

        if (empty($config)) {
            echo "No configuration set.\n";
            echo "\n";
            echo "Config file: " . $this->config->getConfigPath() . "\n";
            return 0;
        }

        echo "Hordectl Configuration:\n";
        echo "======================\n";
        echo "\n";

        foreach ($config as $key => $value) {
            printf("  %-20s = %s\n", $key, $value);
        }

        echo "\n";
        echo "Config file: " . $this->config->getConfigPath() . "\n";

        return 0;
    }

    private function configSet(string $key, string $value): int
    {
        $this->config->set($key, $value);

        if ($this->config->save()) {
            echo "✓ Configuration updated: $key = $value\n";
            echo "  Saved to: " . $this->config->getConfigPath() . "\n";
            return 0;
        } else {
            fwrite(STDERR, "Error: Failed to save configuration\n");
            return 1;
        }
    }

    private function configUnset(string $key): int
    {
        if (!$this->config->has($key)) {
            fwrite(STDERR, "Error: Configuration key '$key' not found\n");
            return 1;
        }

        $this->config->set($key, null);
        $config = $this->config->getAll();
        unset($config[$key]);

        // Rebuild config without the key
        $newConfig = new ConfigManager($this->config->getConfigPath());
        foreach ($config as $k => $v) {
            $newConfig->set($k, $v);
        }

        if ($newConfig->save()) {
            echo "✓ Configuration key removed: $key\n";
            return 0;
        } else {
            fwrite(STDERR, "Error: Failed to save configuration\n");
            return 1;
        }
    }

    private function statusCommand(): int
    {
        echo "\n";
        echo "Horde Installation Status:\n";
        echo "==========================\n";
        echo "\n";

        // Check HORDE_BASE
        $hordeBase = $this->config->get('HORDE_BASE');
        if ($hordeBase) {
            echo "Horde Base: $hordeBase\n";

            if (file_exists($hordeBase . '/lib/Application.php')) {
                echo "  ✓ Application.php found\n";
            } else if (file_exists($hordeBase . '/src/Application.php')) {
                echo "  ✓ Application.php found (src/)\n";
            } else {
                echo "  ✗ Application.php not found\n";
            }

            // Check for config files
            $configDir = $hordeBase . '/config';
            if (is_dir($configDir)) {
                echo "  ✓ Config directory exists\n";

                if (file_exists($configDir . '/conf.php')) {
                    echo "    ✓ conf.php exists\n";
                } else {
                    echo "    ✗ conf.php missing (Horde not configured)\n";
                }

                if (file_exists($configDir . '/registry.php')) {
                    echo "    ✓ registry.php exists\n";
                } else {
                    echo "    ✗ registry.php missing\n";
                }
            } else {
                echo "  ✗ Config directory not found\n";
            }
        } else {
            echo "Horde Base: Not configured\n";
        }

        echo "\n";

        // Check HORDE_INSTALL_DIR
        $installDir = $this->config->get('HORDE_INSTALL_DIR');
        if ($installDir) {
            echo "Installation Directory: $installDir\n";

            if (file_exists($installDir . '/composer.json')) {
                echo "  ✓ composer.json found\n";
            } else {
                echo "  ✗ composer.json not found\n";
            }

            if (is_dir($installDir . '/vendor')) {
                echo "  ✓ vendor directory exists\n";
            } else {
                echo "  ✗ vendor directory missing (run composer install)\n";
            }
        }

        echo "\n";

        // Bootstrap test result
        echo "Bootstrap Status:\n";
        echo "  ✗ Horde bootstrap failed (running in minimal mode)\n";
        echo "\n";

        echo "Likely Issues:\n";
        echo "  • Horde configuration is incomplete\n";
        echo "  • Run Horde web installer to complete setup\n";
        echo "  • Check that database is configured and accessible\n";
        echo "\n";

        return 0;
    }

    private function bootstrapCheckCommand(): int
    {
        echo "\n";
        echo "Testing Horde Bootstrap...\n";
        echo "==========================\n";
        echo "\n";

        $hordeBase = $this->config->get('HORDE_BASE');
        if (!$hordeBase) {
            echo "✗ HORDE_BASE not configured\n";
            return 1;
        }

        echo "Horde Base: $hordeBase\n";
        echo "\n";

        // Try to bootstrap
        echo "Attempting to load Application.php...\n";

        try {
            require_once $hordeBase . '/lib/Application.php';
            echo "✓ Application.php loaded\n";
            echo "\n";

            echo "Attempting Horde_Registry::appInit()...\n";

            $app = \Horde_Registry::appInit('horde', ['cli' => true]);

            echo "✓ Bootstrap successful!\n";
            echo "\n";
            echo "Horde is now properly configured. Try running hordectl again.\n";
            echo "\n";
            return 0;

        } catch (\Throwable $e) {
            echo "✗ Bootstrap failed\n";
            echo "\n";
            echo "Error: " . $e->getMessage() . "\n";
            echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
            echo "\n";
            echo "This is why hordectl is running in minimal mode.\n";
            echo "\n";
            return 1;
        }
    }

    private function reconfigureCommand(array $args): int
    {
        // Parse arguments
        $options = [
            'mode' => null,
            'webroot' => null,
            'force' => false,
            'help' => false,
        ];

        for ($i = 0; $i < count($args); $i++) {
            switch ($args[$i]) {
                case '--help':
                case '-h':
                    $options['help'] = true;
                    break;
                case '--mode':
                    if (!isset($args[$i + 1])) {
                        fwrite(STDERR, "Error: --mode requires a value\n");
                        return 1;
                    }
                    $options['mode'] = $args[++$i];
                    break;
                case '--webroot':
                    if (!isset($args[$i + 1])) {
                        fwrite(STDERR, "Error: --webroot requires a value\n");
                        return 1;
                    }
                    $options['webroot'] = $args[++$i];
                    break;
                case '--force':
                    $options['force'] = true;
                    break;
                default:
                    fwrite(STDERR, "Error: Unknown option '{$args[$i]}'\n");
                    fwrite(STDERR, "Use --help to see available options\n");
                    return 1;
            }
        }

        // Show help if requested
        if ($options['help']) {
            $this->showReconfigureHelp();
            return 0;
        }

        echo "\n";
        echo "Horde Reconfiguration\n";
        echo "=====================\n";
        echo "\n";

        // Check HORDE_INSTALL_DIR is configured
        $installDir = $this->config->get('HORDE_INSTALL_DIR');
        if (!$installDir) {
            fwrite(STDERR, "Error: Horde installation directory not configured.\n");
            fwrite(STDERR, "\n");
            fwrite(STDERR, "Please set HORDE_INSTALL_DIR first:\n");
            fwrite(STDERR, "  hordectl config set HORDE_INSTALL_DIR /path/to/installation\n");
            fwrite(STDERR, "\n");
            return 1;
        }

        echo "Installation Directory: $installDir\n";

        // Validate composer.json exists
        if (!file_exists($installDir . '/composer.json')) {
            fwrite(STDERR, "\nError: composer.json not found in $installDir\n");
            fwrite(STDERR, "This does not appear to be a valid Horde installation.\n");
            return 1;
        }

        echo "  ✓ composer.json found\n";

        // Detect composer binary
        $composerHelper = new ComposerHelper();
        try {
            $composerBin = $composerHelper->detectComposerBin();
            echo "  ✓ Composer found: $composerBin\n";
        } catch (\RuntimeException $e) {
            fwrite(STDERR, "\nError: " . $e->getMessage() . "\n");
            fwrite(STDERR, "\n");
            fwrite(STDERR, "Install composer from https://getcomposer.org/\n");
            fwrite(STDERR, "\n");
            return 1;
        }

        // Build composer command
        $command = escapeshellarg($composerBin) . ' horde:reconfigure';

        if ($options['mode']) {
            $command .= ' --mode ' . escapeshellarg($options['mode']);
        }

        if ($options['webroot']) {
            $command .= ' --webroot ' . escapeshellarg($options['webroot']);
        }

        if ($options['force']) {
            $command .= ' --force';
        }

        echo "\n";
        echo "Running: $command\n";
        echo "Working directory: $installDir\n";
        echo "\n";
        echo str_repeat('-', 60) . "\n";
        echo "\n";

        // Execute command
        $exitCode = $this->executeCommand($command, $installDir);

        echo "\n";
        echo str_repeat('-', 60) . "\n";
        echo "\n";

        if ($exitCode === 0) {
            echo "✓ Reconfiguration completed successfully\n";
            echo "\n";
            echo "Next steps:\n";
            echo "  • Run 'hordectl status' to check installation\n";
            echo "  • Visit your Horde installation in a browser\n";
            echo "  • Complete web-based configuration if needed\n";
            echo "\n";
        } else {
            fwrite(STDERR, "✗ Reconfiguration failed with exit code $exitCode\n");
            fwrite(STDERR, "\n");
            fwrite(STDERR, "Common issues:\n");
            fwrite(STDERR, "  • horde-installer-plugin not installed\n");
            fwrite(STDERR, "    Run: cd $installDir && composer require horde/horde-installer-plugin\n");
            fwrite(STDERR, "  • Permission issues (check file/directory permissions)\n");
            fwrite(STDERR, "  • Invalid installation directory\n");
            fwrite(STDERR, "\n");
        }

        return $exitCode;
    }

    private function showReconfigureHelp(): void
    {
        echo "\n";
        echo "Hordectl Reconfigure Command\n";
        echo "============================\n";
        echo "\n";
        echo "Usage: hordectl reconfigure [options]\n";
        echo "\n";
        echo "Runs the Horde installer plugin to configure your installation.\n";
        echo "This creates web/, var/config/, and other necessary directories and files.\n";
        echo "\n";
        echo "Options:\n";
        echo "\n";
        echo "  --mode <mode>       Installation mode (default: proxy)\n";
        echo "                      • symlink - Create symlinks to vendor packages\n";
        echo "                      • proxy   - Create proxy PHP files (recommended)\n";
        echo "                      • copy    - Copy files from vendor packages\n";
        echo "\n";
        echo "  --webroot <path>    Web root path (default: /)\n";
        echo "                      The URL path where Horde is accessible\n";
        echo "                      Examples: /, /horde, /mail\n";
        echo "\n";
        echo "  --force             Delete and rewrite normally untouched files\n";
        echo "                      Use with caution - overwrites existing config\n";
        echo "\n";
        echo "  --help              Show this help message\n";
        echo "\n";
        echo "Examples:\n";
        echo "\n";
        echo "  # Basic reconfiguration (proxy mode, webroot /)\n";
        echo "  hordectl reconfigure\n";
        echo "\n";
        echo "  # Use symlink mode for development\n";
        echo "  hordectl reconfigure --mode symlink\n";
        echo "\n";
        echo "  # Configure for /horde URL path\n";
        echo "  hordectl reconfigure --webroot /horde\n";
        echo "\n";
        echo "  # Force reconfiguration (overwrites config)\n";
        echo "  hordectl reconfigure --force\n";
        echo "\n";
        echo "Prerequisites:\n";
        echo "\n";
        echo "  • HORDE_INSTALL_DIR must be configured\n";
        echo "    (use 'hordectl config set HORDE_INSTALL_DIR /path')\n";
        echo "  • Composer must be installed\n";
        echo "  • horde-installer-plugin must be installed\n";
        echo "    (run 'composer require horde/horde-installer-plugin' in install dir)\n";
        echo "\n";
    }

    private function executeCommand(string $command, string $workingDir): int
    {
        $oldDir = getcwd();
        chdir($workingDir);

        passthru($command, $exitCode);

        chdir($oldDir);
        return $exitCode;
    }
}
