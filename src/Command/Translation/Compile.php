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
use Horde\Hordectl\Output\Table;
use Horde\Hordectl\TargetCapabilityTrait;
use Horde\Injector\Injector;

/**
 * hordectl translation compile
 *
 * Compiles each translatable package's .po into .mo via msgfmt.
 * Prints a stats table (translated / fuzzy / untranslated per
 * package x locale) at the end via Output::table().
 *
 * Renamed from the legacy tool's `make` for clarity.
 */
final class Compile implements Module, ModuleUsage
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
        return ['compile'];
    }

    public function getBaseOptions(): array
    {
        return [
            new Option('--locale', ['action' => 'store', 'type' => 'string',
                'help' => 'Locale to compile (required)']),
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
        if (empty($argv) || $argv[0] !== 'compile') {
            return false;
        }
        $target = $this->requireFilesystemCapability();
        [$opts, $args] = $this->handleCommandline($argv);

        if (!$this->requireBinaries(['msgfmt'])) {
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

        $rows = [];
        foreach ($packages as $package) {
            $row = $this->compileOne($package, $locale, $verbose, $dryRun);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        if ($rows !== []) {
            $this->cli->writeln('');
            $this->output->table(new Table(
                headers: ['Package', 'Locale', 'Translated', 'Fuzzy', 'Untranslated'],
                rows: $rows,
                alignments: ['left', 'left', 'right', 'right', 'right'],
                caption: 'Compilation summary',
            ));
        }
        return true;
    }

    /**
     * @return list<int|string>|null  A row for the stats table, or null if the package was skipped.
     */
    private function compileOne(Package $package, string $locale, bool $verbose, bool $dryRun): ?array
    {
        $po = $package->poFile($locale);
        $mo = $package->moFile($locale);
        if (!is_file($po)) {
            return null;
        }

        if ($dryRun) {
            $this->output->info(sprintf('Would compile %s -> %s', $po, $mo));
            return null;
        }

        $this->output->info(sprintf('Compiling %s (%s)...', $package->id, $locale));
        $stats = $this->runGettext('msgfmt', ['-c', '--statistics', $po, '-o', $mo]);
        if ($stats['exitCode'] !== 0) {
            $this->output->error(sprintf(
                'msgfmt failed for %s: %s',
                $po,
                trim($stats['stderr'])
            ));
            return null;
        }
        if ($verbose) {
            $this->output->info('  ' . $stats['command']);
        }
        [$translated, $fuzzy, $untranslated] = $this->parseMsgfmtStats($stats['stderr']);
        $this->output->ok(sprintf('  %s', $mo));
        return [$package->id, $locale, $translated, $fuzzy, $untranslated];
    }

    /**
     * Parses msgfmt --statistics output.
     *
     * The line looks like: "931 translated messages, 48 fuzzy translations, 99 untranslated messages."
     * Any of the three fields may be absent (msgfmt drops zero counts).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function parseMsgfmtStats(string $stderr): array
    {
        $translated = 0;
        $fuzzy = 0;
        $untranslated = 0;
        if (preg_match('/(\d+) translated/', $stderr, $m)) {
            $translated = (int) $m[1];
        }
        if (preg_match('/(\d+) fuzzy/', $stderr, $m)) {
            $fuzzy = (int) $m[1];
        }
        if (preg_match('/(\d+) untranslated/', $stderr, $m)) {
            $untranslated = (int) $m[1];
        }
        return [$translated, $fuzzy, $untranslated];
    }
}
