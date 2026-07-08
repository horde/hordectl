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
 * hordectl translation cleanup
 *
 * Strips untranslated and obsolete msgids from each package's per-locale
 * .po via msgattrib. Legacy horde-translation folded this into the
 * merge step, which surprised translators (they lost draft strings
 * whenever they ran a normal merge). Here it's a deliberate, opt-in
 * subcommand. See functional issue #1 in improvements-2026-07-08.md.
 *
 * Default behavior: `--translated --no-obsolete`. Both flags can be
 * relaxed via --keep-untranslated / --keep-obsolete for translators
 * who want to preserve draft entries.
 */
final class Cleanup implements Module, ModuleUsage
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
        return ['cleanup'];
    }

    public function getBaseOptions(): array
    {
        return [
            new Option('--locale', ['action' => 'store', 'type' => 'string',
                'help' => 'Locale to clean up (required)']),
            new Option('--package', ['action' => 'store', 'type' => 'string',
                'help' => 'Restrict to a single package id']),
            new Option('--module', ['action' => 'store', 'type' => 'string',
                'help' => 'Legacy alias for --package']),
            new Option('--source', ['action' => 'store', 'type' => 'string',
                'help' => 'Package discovery source: composer (default) or repos']),
            new Option('--keep-untranslated', ['action' => 'store_true',
                'help' => 'Preserve untranslated msgids (default: drop them)']),
            new Option('--keep-obsolete', ['action' => 'store_true',
                'help' => 'Preserve obsolete entries (default: drop them)']),
            new Option('--dry-run', ['action' => 'store_true',
                'help' => 'Print commands without executing them']),
            new Option('-v', '--verbose', ['action' => 'store_true',
                'help' => 'Print every gettext invocation before running']),
        ];
    }

    public function handle(array $argv = []): bool
    {
        if (empty($argv) || $argv[0] !== 'cleanup') {
            return false;
        }
        $target = $this->requireFilesystemCapability();
        [$opts, $args] = $this->handleCommandline($argv);

        if (!$this->requireBinaries(['msgattrib'])) {
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
        $keepUntranslated = (bool) ($opts->keep_untranslated ?? false);
        $keepObsolete = (bool) ($opts->keep_obsolete ?? false);
        $packageFilter = $opts->package ?? $opts->module ?? null;

        $packages = $this->filterByPackage(
            $this->discoverPackages($installDir, $source),
            $packageFilter,
        );
        if ($packages === []) {
            $this->output->warn('No translatable packages found.');
            return true;
        }

        foreach ($packages as $package) {
            $this->cleanupOne($package, $locale, $keepUntranslated, $keepObsolete, $verbose, $dryRun);
        }
        return true;
    }

    private function cleanupOne(
        Package $package,
        string $locale,
        bool $keepUntranslated,
        bool $keepObsolete,
        bool $verbose,
        bool $dryRun,
    ): void {
        $po = $package->poFile($locale);
        if (!is_file($po)) {
            return;
        }

        // msgattrib writes to a temporary file and we swap it into
        // place on success. This mimics the atomic-replace pattern
        // used by mv(1) and keeps the source po intact when the
        // gettext tool exits non-zero.
        $tmp = $po . '.cleanup.tmp';
        $args = [];
        if (!$keepUntranslated) {
            $args[] = '--translated';
        }
        if (!$keepObsolete) {
            $args[] = '--no-obsolete';
        }
        if ($args === []) {
            $this->output->info(sprintf('Skipping %s: no filters requested.', $package->id));
            return;
        }
        $args[] = '--output-file=' . $tmp;
        $args[] = $po;

        if ($dryRun) {
            $this->output->info(sprintf('Would clean %s', $po));
            $this->output->info('  msgattrib ' . implode(' ', $args));
            return;
        }

        $this->output->info(sprintf('Cleaning %s (%s)...', $package->id, $locale));
        if (!$this->runGettextChecked('msgattrib', $args, $verbose)) {
            @unlink($tmp);
            return;
        }
        if (!rename($tmp, $po)) {
            $this->output->error(sprintf('Could not replace %s with cleaned output.', $po));
            @unlink($tmp);
            return;
        }
        $this->output->ok(sprintf('  %s', $po));
    }
}
