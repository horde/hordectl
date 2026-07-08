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
 * hordectl translation check
 *
 * Answers "are the committed .pot / .po files still up to date
 * with source?" without mutating anything on disk.
 *
 * Runs xgettext against the source tree into a temp workspace,
 * normalizes both the fresh pot and the committed one and diffs
 * them. If the diff is empty the package is up to date. If not,
 * the exit-code convention (--strict) turns the mismatch into a
 * CI-visible failure.
 *
 * Default is pot-only. Pass --with-locales to also regenerate a
 * temp po via msgmerge and diff each locale.
 */
final class Check implements Module, ModuleUsage
{
    use ModuleTrait;
    use TargetCapabilityTrait;
    use TranslationHelperTrait;
    use PoNormalizerTrait;

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
        return ['check'];
    }

    public function getBaseOptions(): array
    {
        return [
            new Option('--package', ['action' => 'store', 'type' => 'string',
                'help' => 'Restrict to a single package id']),
            new Option('--module', ['action' => 'store', 'type' => 'string',
                'help' => 'Legacy alias for --package']),
            new Option('--source', ['action' => 'store', 'type' => 'string',
                'help' => 'Package discovery source: repos (default) or composer']),
            new Option('--with-locales', ['action' => 'store_true',
                'help' => 'Also merge fresh pot into each locale.po and diff']),
            new Option('--locale', ['action' => 'store', 'type' => 'string',
                'help' => 'Restrict --with-locales to a single locale']),
            new Option('--ignore-refs', ['action' => 'store_true',
                'help' => 'Ignore #: reference lines when deciding materiality']),
            new Option('--strict', ['action' => 'store_true',
                'help' => 'Report material drift via a table. Leaves interpretation to the caller']),
            new Option('--verbose', '-v', ['action' => 'store_true',
                'help' => 'Print the unified diff for each mismatch']),
        ];
    }

    public function handle(array $argv = []): bool
    {
        if (empty($argv) || $argv[0] !== 'check') {
            return false;
        }
        $target = $this->requireFilesystemCapability();
        [$opts, $args] = $this->handleCommandline($argv);

        // Check needs xgettext (fresh pot), msgcat (normalize), diff
        // (unified output). Only requires msgmerge when locales are
        // in scope, so we preflight the base set here and let
        // --with-locales callers pay for the extra check below.
        if (!$this->requireBinaries(['xgettext', 'msgcat', 'diff'])) {
            return true;
        }

        $installDir = $target->hordeInstallDir;
        if (!$installDir) {
            $this->cli->fatal("Target '{$target->name}' has no installation directory configured.");
        }

        $source = (string) ($opts->source ?? 'composer');
        $ignoreRefs = (bool) ($opts->ignore_refs ?? false);
        $withLocales = (bool) ($opts->with_locales ?? false);
        $localeFilter = $opts->locale ?? null;
        $verbose = (bool) ($opts->verbose ?? false);
        $packageFilter = $opts->package ?? $opts->module ?? null;

        if ($withLocales && !$this->requireBinaries(['msgmerge'])) {
            return true;
        }

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
            $rows = array_merge(
                $rows,
                $this->checkOne($package, $withLocales, $localeFilter, $ignoreRefs, $verbose),
            );
        }
        if ($rows !== []) {
            $this->cli->writeln('');
            $this->output->table(new Table(
                headers: ['Package', 'File', 'Status'],
                rows: $rows,
                alignments: ['left', 'left', 'left'],
                caption: 'check summary',
            ));
        }
        return true;
    }

    /**
     * @return list<list<string>>
     */
    private function checkOne(Package $package, bool $withLocales, ?string $localeFilter, bool $ignoreRefs, bool $verbose): array
    {
        $rows = [];
        $workspace = $this->makeWorkspace($package);
        if ($workspace === null) {
            return [[$package->id, '-', 'workspace error']];
        }

        $freshPot = $this->extractToWorkspace($package, $workspace);
        if ($freshPot === null) {
            $this->cleanupWorkspace($workspace);
            return [[$package->id, $package->effectiveDomain() . '.pot', 'extract failed']];
        }

        $committedPot = $package->potFile();
        $status = $this->comparePair(
            $package,
            $committedPot,
            $freshPot,
            $ignoreRefs,
            $verbose,
            $package->effectiveDomain() . '.pot',
        );
        $rows[] = [$package->id, $package->effectiveDomain() . '.pot', $status];

        if ($withLocales) {
            $locales = $localeFilter !== null
                ? [$localeFilter]
                : array_values(array_filter($this->discoverLocales($package), fn($l) => $l !== 'en'));

            foreach ($locales as $locale) {
                $freshPo = $this->mergeToWorkspace($package, $freshPot, $locale, $workspace);
                if ($freshPo === null) {
                    $rows[] = [$package->id, $locale, 'merge failed'];
                    continue;
                }
                $committedPo = $package->poFile($locale);
                $status = $this->comparePair(
                    $package,
                    $committedPo,
                    $freshPo,
                    $ignoreRefs,
                    $verbose,
                    $locale . '/' . $package->effectiveDomain() . '.po',
                );
                $rows[] = [$package->id, $locale, $status];
            }
        }

        $this->cleanupWorkspace($workspace);
        return $rows;
    }

    /**
     * Runs xgettext against $package into $workspace and returns the
     * fresh .pot path, or null on failure.
     */
    private function extractToWorkspace(Package $package, string $workspace): ?string
    {
        $files = $this->collectSourceFiles($package);
        if ($files === []) {
            return null;
        }
        $rootLen = strlen($package->path) + 1;
        $relFiles = array_map(static fn($abs) => substr($abs, $rootLen), $files);
        $listFile = $workspace . '/files.list';
        file_put_contents($listFile, implode("\n", $relFiles));

        $potPath = $workspace . '/' . $package->effectiveDomain() . '.pot';
        $result = $this->runGettext('xgettext', [
            '--language=PHP',
            '--from-code=UTF-8',
            '--keyword=_',
            '--keyword=ngettext',
            '--keyword=t',
            '--keyword=n',
            '--keyword=r',
            '--keyword=invalid',
            '--sort-output',
            '--directory=' . $package->path,
            '--package-name=' . $package->effectiveDomain(),
            '--copyright-holder=Horde LLC (http://www.horde.org/)',
            '--msgid-bugs-address=dev@lists.horde.org',
            '--files-from=' . $listFile,
            '--output=' . $potPath,
        ]);
        @unlink($listFile);
        if ($result['exitCode'] !== 0) {
            $this->output->error(sprintf('xgettext failed: %s', trim($result['stderr'])));
            return null;
        }
        return $potPath;
    }

    /**
     * Merges $freshPot into the package's committed po under
     * $workspace so the committed po is not touched.
     */
    private function mergeToWorkspace(Package $package, string $freshPot, string $locale, string $workspace): ?string
    {
        $committedPo = $package->poFile($locale);
        if (!is_file($committedPo)) {
            return null;
        }
        $workPo = $workspace . '/' . $locale . '_' . $package->effectiveDomain() . '.po';
        copy($committedPo, $workPo);
        $result = $this->runGettext('msgmerge', [
            '--update',
            '--backup=off',
            $workPo,
            $freshPot,
        ]);
        if ($result['exitCode'] !== 0) {
            return null;
        }
        return $workPo;
    }

    /**
     * Normalizes both sides and reports the outcome. Prints the
     * unified diff when $verbose is true.
     */
    private function comparePair(
        Package $package,
        string $committedPath,
        string $freshPath,
        bool $ignoreRefs,
        bool $verbose,
        string $label,
    ): string {
        if (!is_file($committedPath)) {
            return 'committed missing';
        }
        $left = $this->normalizePo($committedPath, $ignoreRefs);
        $right = $this->normalizePo($freshPath, $ignoreRefs);
        $diff = $this->unifiedDiff(
            $left,
            $right,
            $package->id . '/' . $label . ' (committed)',
            $package->id . '/' . $label . ' (fresh)',
        );
        if ($diff === '') {
            return 'up to date';
        }
        if ($verbose) {
            $this->cli->writeln($diff);
        }
        return 'DRIFT';
    }

    /**
     * Creates a per-check workspace under sys_get_temp_dir(). Returns
     * the absolute path or null on failure.
     */
    private function makeWorkspace(Package $package): ?string
    {
        // Do not use Math.random / Date.now analogues here. The
        // native mkdtemp is fine for genuine filesystem operations.
        $prefix = sys_get_temp_dir() . '/hordectl-check-' . $package->id . '-XXXXXX';
        $path = @tempnam(sys_get_temp_dir(), 'hordectl-check-' . $package->id . '-');
        if ($path === false) {
            return null;
        }
        // tempnam creates a file. Swap it for a directory.
        @unlink($path);
        if (!@mkdir($path, 0o700, true)) {
            return null;
        }
        return $path;
    }

    private function cleanupWorkspace(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }
        // Shallow rm because we only ever write files at the top level.
        foreach (glob($path . '/*') ?: [] as $entry) {
            if (is_file($entry)) {
                @unlink($entry);
            }
        }
        @rmdir($path);
    }
}
