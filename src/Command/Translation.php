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
 * Translation command module. Modern replacement for the legacy
 * horde-translation tool.
 *
 * Subcommands operate on translatable Horde packages (applications
 * and libraries) discovered via .horde.yml `type:` field. See
 * ~/php/horde-development/tools/hordectl/hordectl-translation-subcommand-plan-2026-07-08.md
 * for the design.
 */
class Translation implements Module, ModuleUsage
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
            '\Horde\Hordectl\Command\Translation',
            dirname(__FILE__) . '/Translation',
            ['TranslationHelperTrait', 'PoNormalizerTrait', 'Package']
        );
    }

    public function getBaseOptions(): array
    {
        return [];
    }

    /**
     * Decide if this module handles the commandline.
     *
     * @param array $argv The arguments for the parser to digest
     * @return bool True if command was handled
     */
    public function handle(array $argv = []): bool
    {
        if (count($argv) < 1) {
            return false;
        }

        if ($argv[0] !== 'translation' && $argv[0] !== 'i18n') {
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
            $this->output->error("Unknown translation subcommand: {$argv[0]}");
            $this->cli->writeln();
            $this->showUsage();
        }

        return true;
    }

    protected function showUsage(): void
    {
        $this->cli->writeln();
        $this->cli->writeln('Usage: hordectl translation SUBCOMMAND [OPTIONS]');
        $this->cli->writeln('       hordectl i18n SUBCOMMAND [OPTIONS]');
        $this->cli->writeln();
        $this->cli->writeln('Extract, merge, compile and manage translations across Horde packages.');
        $this->cli->writeln();
        $this->cli->writeln($this->cli->yellow('Note: Translation commands require a local target with filesystem access.'));
        $this->cli->writeln();
        $this->cli->writeln('Available subcommands:');

        $subcommands = $this->listModules();
        if (empty($subcommands)) {
            $this->cli->writeln('  (No subcommands available yet)');
        } else {
            foreach ($subcommands as $class => $module) {
                $positional = $module instanceof ModuleUsage
                    ? $module->getPositionalArgs()
                    : [];
                $name = $positional !== []
                    ? (string) $positional[0]
                    : strtolower(basename(str_replace('\\', '/', $class)));
                $description = $this->getSubcommandDescription($name);
                $this->cli->writeln('  ' . str_pad($name, 15) . ' ' . $description);
            }
        }

        $this->cli->writeln();
        $this->cli->writeln('For cross-package translation reuse, run');
        $this->cli->writeln('  hordectl translation compendium --locale=<locale>');
        $this->cli->writeln('before `merge` to have msgmerge draw on the compendium.');
        $this->cli->writeln();
        $this->cli->writeln('Run \'hordectl translation SUBCOMMAND --help\' for more information on a subcommand.');
        $this->cli->writeln();
    }

    protected function getSubcommandDescription(string $name): string
    {
        $descriptions = [
            'extract' => 'Generate .pot template files from source',
            'merge' => 'Merge .pot into locale .po (without stripping untranslated)',
            'compile' => 'Compile .po to .mo, print stats table',
            'init' => 'Bootstrap a new locale for a package',
            'cleanup' => 'Strip untranslated and obsolete entries from .po',
            'compendium' => 'Rebuild per-locale compendium.<locale>.po',
            'update' => 'Run extract then merge in sequence',
            'diff' => 'Compare two revisions of a translation file after normalization',
            'check' => 'Report drift between committed and freshly-regenerated files',
            'updatehelp' => 'Merge English help.xml into locale help.xml',
            'update-help' => 'Merge English help.xml into locale help.xml',
            'makehelp' => 'Mark reviewed help.xml entries as up-to-date',
            'make-help' => 'Mark reviewed help.xml entries as up-to-date',
            'commit' => 'git add + git commit for translation changes',
            'binaries' => 'Report which gettext tools are available on PATH',
            'show' => 'Load a compiled .mo and print every msgid + translation',
        ];

        return $descriptions[$name] ?? '';
    }
}
