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
 * hordectl translation init
 *
 * Creates a fresh <locale>/LC_MESSAGES/<id>.po for each translatable
 * package via msginit. Fails cleanly when the .pot is missing or
 * when the .po already exists (the user must delete first).
 *
 * Refuses to overwrite an existing po unless --force is passed.
 * Legacy horde-translation had no equivalent guard.
 */
final class Init implements Module, ModuleUsage
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
        return ['init'];
    }

    public function getBaseOptions(): array
    {
        return [
            new Option('--locale', ['action' => 'store', 'type' => 'string',
                'help' => 'Locale to initialize (required)']),
            new Option('--package', ['action' => 'store', 'type' => 'string',
                'help' => 'Restrict to a single package id']),
            new Option('--module', ['action' => 'store', 'type' => 'string',
                'help' => 'Legacy alias for --package']),
            new Option('--source', ['action' => 'store', 'type' => 'string',
                'help' => 'Package discovery source: composer (default) or repos']),
            new Option('--force', ['action' => 'store_true',
                'help' => 'Overwrite an existing .po (default: skip and warn)']),
            new Option('--dry-run', ['action' => 'store_true',
                'help' => 'Print commands without executing them']),
            new Option('-v', '--verbose', ['action' => 'store_true',
                'help' => 'Print every gettext invocation before running']),
        ];
    }

    public function handle(array $argv = []): bool
    {
        if (empty($argv) || $argv[0] !== 'init') {
            return false;
        }
        $target = $this->requireFilesystemCapability();
        [$opts, $args] = $this->handleCommandline($argv);

        if (!$this->requireBinaries(['msginit'])) {
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
        $force = (bool) ($opts->force ?? false);
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
            $this->initOne($package, $locale, $force, $verbose, $dryRun);
        }
        return true;
    }

    private function initOne(Package $package, string $locale, bool $force, bool $verbose, bool $dryRun): void
    {
        $pot = $package->potFile();
        $po = $package->poFile($locale);

        if (!is_file($pot)) {
            $this->output->warn(sprintf(
                'Skipping %s: no .pot yet (run `extract --package=%s` first).',
                $package->id,
                $package->id
            ));
            return;
        }
        if (is_file($po) && !$force) {
            $this->output->info(sprintf(
                'Skipping %s: %s already exists (pass --force to overwrite).',
                $package->id,
                $po
            ));
            return;
        }

        $poDir = dirname($po);
        if (!is_dir($poDir)) {
            if ($dryRun) {
                $this->output->info(sprintf('  would mkdir %s', $poDir));
            } else {
                mkdir($poDir, 0o755, true);
            }
        }

        // msginit picks up $LANG when --locale is not passed. Force
        // the argument explicitly so the caller's environment cannot
        // silently change the outcome.
        $args = [
            '--input=' . $pot,
            '--output-file=' . $po,
            '--locale=' . $locale,
            '--no-translator',
            '--no-wrap',
        ];

        if ($dryRun) {
            $this->output->info(sprintf('Would init %s (%s)', $package->id, $locale));
            $this->output->info('  msginit ' . implode(' ', $args));
            return;
        }

        $this->output->info(sprintf('Initializing %s (%s)...', $package->id, $locale));
        if ($this->runGettextChecked('msginit', $args, $verbose)) {
            $this->output->ok(sprintf('  %s', $po));
        }
    }
}
