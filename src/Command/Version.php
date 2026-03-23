<?php

namespace Horde\Hordectl\Command;

use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Injector\Injector;
use Horde\Cli\Cli as HordeCli;

/**
 * Version command - displays hordectl version information
 */
class Version implements Module, ModuleUsage
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
        if ($command === 'version' || $command === '--version' || $command === '-v') {
            $this->showVersion();
            return true;
        }

        return false;
    }

    /**
     * Display version information
     */
    protected function showVersion(): void
    {
        // Try to read version from composer.json
        $composerJson = dirname(__DIR__, 2) . '/composer.json';
        $version = 'unknown';

        if (file_exists($composerJson)) {
            $data = json_decode(file_get_contents($composerJson), true);
            $version = $data['version'] ?? 'dev-main';
        }

        $this->cli->writeln("hordectl version {$version}");
    }

    /**
     * Get module usage information
     *
     * @return ModuleUsage
     */
    public function getUsage(): string
    {
        return 'Display version information';
    }

    /**
     * Get module title
     *
     * @return string
     */
    public function getTitle(): string
    {
        return 'version';
    }

    /**
     * Get module description
     *
     * @return string
     */
    public function getUsageDescription(): array
    {
        return [
            'Display hordectl version information',
            '',
            'Usage:',
            '  hordectl version',
            '  hordectl --version',
            '  hordectl -v',
        ];
    }
}
