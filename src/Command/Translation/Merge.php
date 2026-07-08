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
 * hordectl translation merge
 *
 * Merges each translatable package's .pot file into its per-locale
 * .po via msgmerge --update. Preserves existing translations. Adds
 * new msgids as untranslated msgstrs so translators see the work.
 *
 * Does NOT run cleanup implicitly. Untranslated entries stay in the
 * po until an explicit `hordectl translation cleanup` prunes them.
 * See functional issue #1 in improvements-2026-07-08.md.
 *
 * Locale-suffixed compendium: reads <install>/vendor/horde/horde/locale/compendium.<locale>.po
 * when present, skips the --compendium argument entirely otherwise.
 * See functional issue #2 in improvements-2026-07-08.md.
 */
final class Merge implements Module, ModuleUsage
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
        return ['merge'];
    }

    public function getBaseOptions(): array
    {
        return [
            new Option('--locale', ['action' => 'store', 'type' => 'string',
                'help' => 'Locale to merge into (required)']),
            new Option('--package', ['action' => 'store', 'type' => 'string',
                'help' => 'Restrict to a single package id']),
            new Option('--module', ['action' => 'store', 'type' => 'string',
                'help' => 'Legacy alias for --package']),
            new Option('--source', ['action' => 'store', 'type' => 'string',
                'help' => 'Package discovery source: composer (default) or repos']),
            new Option('--dry-run', ['action' => 'store_true',
                'help' => 'Print commands without executing them']),
            new Option('-v', '--verbose', ['action' => 'store_true',
                'help' => 'Print every gettext invocation before running']),
        ];
    }

    public function handle(array $argv = []): bool
    {
        if (empty($argv) || $argv[0] !== 'merge') {
            return false;
        }
        $target = $this->requireFilesystemCapability();
        [$opts, $args] = $this->handleCommandline($argv);

        if (!$this->requireBinaries(['msgmerge'])) {
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
        $packageFilter = $opts->package ?? $opts->module ?? null;

        $packages = $this->filterByPackage(
            $this->discoverPackages($installDir, $source),
            $packageFilter,
        );
        if ($packages === []) {
            $this->output->warn('No translatable packages found.');
            return true;
        }

        $compendium = $this->resolveCompendium($installDir, $locale, $source);

        foreach ($packages as $package) {
            $this->mergeOne($package, $locale, $compendium, $verbose, $dryRun);
        }
        return true;
    }

    /**
     * Resolves the compendium path for the given locale. Returns
     * null when the file doesn't exist, in which case msgmerge runs
     * without --compendium.
     */
    private function resolveCompendium(string $installDir, string $locale, string $source): ?string
    {
        $localeDir = $source === 'repos'
            ? $installDir . '/base/locale'
            : $installDir . '/vendor/horde/horde/locale';
        $path = $localeDir . '/compendium.' . $locale . '.po';
        return is_file($path) ? $path : null;
    }

    private function mergeOne(Package $package, string $locale, ?string $compendium, bool $verbose, bool $dryRun): void
    {
        $po = $package->poFile($locale);
        $pot = $package->potFile();

        if (!is_file($po)) {
            $this->output->info(sprintf(
                'Skipping %s: no %s.po yet (run `init --locale=%s --package=%s` first).',
                $package->id,
                $locale,
                $locale,
                $package->id
            ));
            return;
        }
        if (!is_file($pot)) {
            $this->output->info(sprintf(
                'Skipping %s: no .pot yet (run `extract --package=%s` first).',
                $package->id,
                $package->id
            ));
            return;
        }

        $args = ['--update', '--backup=off'];
        if ($compendium !== null) {
            $args[] = '--compendium=' . $compendium;
        }
        $args[] = $po;
        $args[] = $pot;

        if ($dryRun) {
            $this->output->info(sprintf('Would merge %s from %s', $po, $pot));
            $this->output->info('  msgmerge ' . implode(' ', $args));
            return;
        }

        $this->output->info(sprintf('Merging %s (%s)...', $package->id, $locale));
        if ($this->runGettextChecked('msgmerge', $args, $verbose)) {
            $this->output->ok(sprintf('  %s', $po));
        }
    }
}
