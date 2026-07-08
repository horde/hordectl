<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Command;

use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;
use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;
use Horde\Hordectl\HasModulesTrait;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Injector\Injector;

/**
 * `hordectl webserver-config` top-level dispatcher.
 *
 * Emits webserver configuration derived from the target's registry.
 * Sub-flavors:
 *   - htaccess       (regenerates registry-aware .htaccess files)
 *   - apache-vhost   (Apache VirtualHost blocks)
 *   - nginx          (nginx server blocks)
 *
 * Design and rationale live in
 * ~/php/horde-development/tools/hordectl/hordectl-webserver-config-plan-2026-07-08.md
 */
class WebserverConfig implements Module, ModuleUsage
{
    use ModuleTrait;
    use HasModulesTrait;

    protected HordeCli $cli;
    protected Output $output;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->output = $dependencies->createOutput($this->cli);
        $this->_parser = $dependencies->getInstance(Parser::class);
        $this->_parser->allowInterspersedArgs = false;
        $this->_initModules(
            $dependencies,
            '\Horde\Hordectl\Command\WebserverConfig',
            dirname(__FILE__) . '/WebserverConfig',
            ['WebserverConfigOptions'],
        );
    }

    public function getTitle(): string
    {
        return 'webserver-config';
    }

    public function getPositionalArgs(): array
    {
        return ['webserver-config'];
    }

    public function getBaseOptions(): array
    {
        return [];
    }

    public function handle(array $argv = []): bool
    {
        if (count($argv) < 1) {
            return false;
        }
        if ($argv[0] !== 'webserver-config') {
            return false;
        }
        array_shift($argv);

        if (empty($argv)) {
            $this->showUsage();
            return true;
        }

        $handled = false;
        [$myArgs, $moduleArgs] = $this->handleCommandline($argv);
        foreach ($this->listModules() as $module) {
            $handled |= $module->handle($moduleArgs);
        }

        if (!$handled) {
            $this->cli->writeln();
            $this->output->error(sprintf('Unknown webserver-config flavor: %s', $argv[0]));
            $this->cli->writeln();
            $this->showUsage();
        }
        return true;
    }

    protected function showUsage(): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Usage: hordectl webserver-config FLAVOR [OPTIONS]');
        $this->cli->writeln();
        $this->cli->writeln('Generate webserver configuration from a Horde registry.');
        $this->cli->writeln();
        $this->cli->writeln($this->cli->yellow('Note: This is a CI-first fringe feature. Every generated file'));
        $this->cli->writeln($this->cli->yellow('carries Prerequisites/Limitations headers. Read them before'));
        $this->cli->writeln($this->cli->yellow('dropping files into a production webserver.'));
        $this->cli->writeln();
        $this->cli->writeln('Available flavors:');
        foreach ($this->listModules() as $class => $module) {
            $positional = $module instanceof ModuleUsage
                ? $module->getPositionalArgs()
                : [];
            $name = $positional !== []
                ? (string) $positional[0]
                : strtolower(basename(str_replace('\\', '/', $class)));
            $this->cli->writeln('  ' . str_pad($name, 15) . ' ' . $this->describeFlavor($name));
        }
        $this->cli->writeln();
        $this->cli->writeln('Input tiers (first available wins):');
        $this->cli->writeln('  1. `hordectl query registry` against the current target (default).');
        $this->cli->writeln('  2. --registry-in=-  Read a YAML registry payload from stdin');
        $this->cli->writeln('                      (the same shape `query registry` emits).');
        $this->cli->writeln('  3. --root-bundle-path=<path> (+ optional --default-url and');
        $this->cli->writeln('     --app-webroots) synthesizes from the vanilla Horde 6 bundle');
        $this->cli->writeln('     layout. Use this for bootstrap ahead of a running registry.');
        $this->cli->writeln();
        $this->cli->writeln('CLI overrides layered on top of tiers 1 and 2:');
        $this->cli->writeln('  --app-webroots=id|url,...   Replace per-app webroots');
        $this->cli->writeln('  --default-url=<url>         Promote relative webroots to <url>/<path>');
        $this->cli->writeln();
    }

    private function describeFlavor(string $name): string
    {
        return match ($name) {
            'htaccess' => 'Registry-aware .htaccess files under each fileroot',
            'apache-vhost' => 'Apache VirtualHost blocks under var/webserver/apache-vhost/',
            'nginx' => 'nginx server blocks under var/webserver/nginx/',
            default => '',
        };
    }
}
