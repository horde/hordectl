<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Command\Translation;

use Horde\Argv\Option;
use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;
use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\TargetCapabilityTrait;
use Horde\Injector\Injector;

/**
 * hordectl translation compendium
 *
 * Builds a per-locale compendium.<locale>.po by msgcat'ing every
 * package's <locale>/LC_MESSAGES/*.po and keeping only translated
 * entries. The result lives at <install>/vendor/horde/horde/locale/
 * (composer source) or <install>/base/locale/ (repos source) and
 * is picked up by `merge --compendium` on subsequent runs.
 *
 * Legacy horde-translation wrote a single compendium.po with no
 * locale in the filename, causing French entries to poison German
 * merges. Fixes functional issue #2 in improvements-2026-07-08.md.
 */
final class Compendium implements Module, ModuleUsage
{
    use ModuleTrait;
    use TargetCapabilityTrait;
    use TranslationHelperTrait;

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

    public function getPositionalArgs(): array
    {
        return ['compendium'];
    }

    public function getBaseOptions(): array
    {
        return [
            new Option('--locale', ['action' => 'store', 'type' => 'string',
                'help' => 'Locale to build the compendium for (required)']),
            new Option('--source', ['action' => 'store', 'type' => 'string',
                'help' => 'Package discovery source: composer (default) or repos']),
            new Option('--output-dir', ['action' => 'store', 'type' => 'string',
                'help' => 'Override the compendium output directory']),
            new Option('--dry-run', ['action' => 'store_true',
                'help' => 'Print commands without executing them']),
            new Option('-v', '--verbose', ['action' => 'store_true',
                'help' => 'Print every gettext invocation before running']),
        ];
    }

    public function handle(array $argv = []): bool
    {
        if (empty($argv) || $argv[0] !== 'compendium') {
            return false;
        }
        $target = $this->requireFilesystemCapability();
        [$opts, $args] = $this->handleCommandline($argv);

        if (!$this->requireBinaries(['msgcat', 'msgattrib'])) {
            return true;
        }

        $installDir = $target->hordeInstallDir;
        if (!$installDir) {
            $this->cli->fatal("Target '{$target->name}' has no installation directory configured.");
        }

        $locale = (string) ($opts->locale ?? '');
        if ($locale === '') {
            $this->output->error('--locale is required.');
            return true;
        }

        $source = (string) ($opts->source ?? 'composer');
        $verbose = (bool) ($opts->verbose ?? false);
        $dryRun = (bool) ($opts->dry_run ?? false);

        $packages = $this->discoverPackages($installDir, $source);
        if ($packages === []) {
            $this->output->warn('No translatable packages found.');
            return true;
        }

        // Gather every po that actually has content for this locale.
        $poFiles = [];
        foreach ($packages as $package) {
            $po = $package->poFile($locale);
            if (is_file($po)) {
                $poFiles[] = $po;
            }
        }
        if ($poFiles === []) {
            $this->output->warn(sprintf('No .po files found for locale %s.', $locale));
            return true;
        }

        $outputDir = $opts->output_dir
            ?? ($source === 'repos'
                ? $installDir . '/base/locale'
                : $installDir . '/vendor/horde/horde/locale');
        $outputPath = rtrim($outputDir, '/') . '/compendium.' . $locale . '.po';

        if (!is_dir($outputDir)) {
            if ($dryRun) {
                $this->output->info(sprintf('  would mkdir %s', $outputDir));
            } else {
                mkdir($outputDir, 0o755, true);
            }
        }

        // msgcat --use-first collapses duplicate msgids to the first
        // translation seen. --less-than=2 would drop duplicates entirely,
        // which is not what we want. After msgcat we run msgattrib to
        // strip untranslated and obsolete so the compendium is nothing
        // but proven translations.
        $tmp = $outputPath . '.tmp';
        $catArgs = array_merge(['--use-first', '--output-file=' . $tmp], $poFiles);

        if ($dryRun) {
            $this->output->info(sprintf('Would build compendium at %s', $outputPath));
            $this->output->info('  msgcat ' . implode(' ', $catArgs));
            return true;
        }

        $this->output->info(sprintf(
            'Concatenating %d .po files for locale %s...',
            count($poFiles),
            $locale
        ));
        if (!$this->runGettextChecked('msgcat', $catArgs, $verbose)) {
            @unlink($tmp);
            return true;
        }

        $attribArgs = ['--translated', '--no-obsolete', '--output-file=' . $outputPath, $tmp];
        if (!$this->runGettextChecked('msgattrib', $attribArgs, $verbose)) {
            @unlink($tmp);
            return true;
        }
        @unlink($tmp);
        $this->output->ok(sprintf('  %s', $outputPath));
        return true;
    }
}
