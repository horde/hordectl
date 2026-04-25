<?php

namespace Horde\Hordectl\Command;

use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;
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
        $version = 'unknown';
        $hordeYml = dirname(__DIR__, 2) . '/.horde.yml';
        if (file_exists($hordeYml)) {
            $data = \Horde\Yaml\Yaml::loadFile($hordeYml);
            $version = $data['version']['release'] ?? 'unknown';
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
