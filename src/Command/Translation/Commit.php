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
 * hordectl translation commit
 *
 * Stages translation artifacts under each package's locale/ tree
 * and writes a single Conventional Commits headline per package.
 * Never pushes. Never creates a branch. Never opens a PR.
 *
 * Default staged paths per package:
 *   locale/*.pot
 *   locale/<locale>/LC_MESSAGES/*.po
 *   locale/<locale>/LC_MESSAGES/*.mo
 *   locale/<locale>/help.xml   (applications only)
 *
 * With --new: adds nls.php, CREDITS, CHANGES if changed.
 *
 * Commit headline: chore(i18n): update <locale> translation for <package>
 * (single line per feedback_commit_headline_only. No body, no bullets).
 *
 * The subcommand only runs against `--source=repos` (i.e. a working
 * tree). Running against the composer install is refused: those
 * directories are not git working trees the translator owns.
 */
final class Commit implements Module, ModuleUsage
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
        return ['commit'];
    }

    public function getBaseOptions(): array
    {
        return [
            new Option('--locale', ['action' => 'store', 'type' => 'string',
                'help' => 'Locale being committed (used in the commit headline, required)']),
            new Option('--package', ['action' => 'store', 'type' => 'string',
                'help' => 'Restrict to a single package id']),
            new Option('--module', ['action' => 'store', 'type' => 'string',
                'help' => 'Legacy alias for --package']),
            new Option('--source', ['action' => 'store', 'type' => 'string',
                'help' => 'Package discovery source: repos (default here) or composer']),
            new Option('--new', ['action' => 'store_true',
                'help' => 'Also stage nls.php, CREDITS, CHANGES for brand-new locales']),
            new Option('--message', ['action' => 'store', 'type' => 'string',
                'help' => 'Override the default Conventional Commits headline']),
            new Option('--dry-run', ['action' => 'store_true',
                'help' => 'Print git commands without executing them']),
            new Option('-v', '--verbose', ['action' => 'store_true',
                'help' => 'Print every git invocation before running']),
        ];
    }

    public function handle(array $argv = []): bool
    {
        if (empty($argv) || $argv[0] !== 'commit') {
            return false;
        }
        $target = $this->requireFilesystemCapability();
        [$opts, $args] = $this->handleCommandline($argv);

        $installDir = $target->hordeInstallDir;
        if (!$installDir) {
            $this->cli->fatal("Target '{$target->name}' has no installation directory configured.");
        }

        $locale = (string) ($opts->locale ?? '');
        if ($locale === '') {
            $this->output->error('--locale is required.');
            return true;
        }

        $source = (string) ($opts->source ?? 'repos');
        if ($source !== 'repos') {
            $this->output->error('commit only runs against --source=repos (a git working tree).');
            return true;
        }

        $verbose = (bool) ($opts->verbose ?? false);
        $dryRun = (bool) ($opts->dry_run ?? false);
        $includeNew = (bool) ($opts->new ?? false);
        $override = $opts->message ?? null;
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
            $row = $this->commitOne($package, $locale, $includeNew, $override, $verbose, $dryRun);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        if ($rows !== []) {
            $this->cli->writeln('');
            $this->output->table(new Table(
                headers: ['Package', 'Result', 'Staged files'],
                rows: $rows,
                alignments: ['left', 'left', 'right'],
                caption: 'commit summary',
            ));
        }
        return true;
    }

    /**
     * Stages translation artifacts and commits one package. Returns
     * a row for the summary table, or null on skip.
     *
     * @return list<string>|null
     */
    private function commitOne(
        Package $package,
        string $locale,
        bool $includeNew,
        ?string $override,
        bool $verbose,
        bool $dryRun,
    ): ?array {
        if (!is_dir($package->path . '/.git')) {
            $this->output->info(sprintf('%s: not a git working tree, skipping.', $package->id));
            return null;
        }

        $paths = $this->collectPaths($package, $locale, $includeNew);
        if ($paths === []) {
            $this->output->info(sprintf('%s: no translation files to stage.', $package->id));
            return [$package->id, 'no changes', '0'];
        }

        // git add. Paths are relative to the package tree.
        $addArgs = array_merge(['-C', $package->path, 'add', '--'], $paths);
        if ($dryRun) {
            $this->output->info(sprintf('Would stage %d file(s) in %s', count($paths), $package->id));
            $this->output->info('  git ' . implode(' ', $addArgs));
        } elseif (!$this->runGit($addArgs, $verbose)) {
            return [$package->id, 'add failed', (string) count($paths)];
        }

        // Bail out if nothing is actually staged (files were already committed).
        if (!$dryRun && !$this->hasStagedChanges($package->path, $verbose)) {
            $this->output->info(sprintf('%s: nothing to commit (already up to date).', $package->id));
            return [$package->id, 'up to date', (string) count($paths)];
        }

        $message = $override ?? sprintf('chore(i18n): update %s translation for %s', $locale, $package->id);
        $commitArgs = ['-C', $package->path, 'commit', '-m', $message];
        if ($dryRun) {
            $this->output->info('  git ' . implode(' ', $commitArgs));
            return [$package->id, 'would commit', (string) count($paths)];
        }
        if (!$this->runGit($commitArgs, $verbose)) {
            return [$package->id, 'commit failed', (string) count($paths)];
        }

        $this->output->ok(sprintf('%s: committed %d file(s).', $package->id, count($paths)));
        return [$package->id, 'committed', (string) count($paths)];
    }

    /**
     * Collects the package-relative paths worth staging for a locale.
     *
     * @return list<string>
     */
    private function collectPaths(Package $package, string $locale, bool $includeNew): array
    {
        $paths = [];
        $root = $package->path;

        $pot = $package->potFile();
        if (is_file($pot)) {
            $paths[] = $this->relative($root, $pot);
        }
        $po = $package->poFile($locale);
        if (is_file($po)) {
            $paths[] = $this->relative($root, $po);
        }
        $mo = $package->moFile($locale);
        if (is_file($mo)) {
            $paths[] = $this->relative($root, $mo);
        }
        if ($package->isApplication()) {
            $help = $package->helpXmlFile($locale);
            if (is_file($help)) {
                $paths[] = $this->relative($root, $help);
            }
        }

        if ($includeNew) {
            foreach (['CREDITS', 'CHANGES', 'locale/' . $locale . '/nls.php'] as $extra) {
                $abs = $root . '/' . $extra;
                if (is_file($abs)) {
                    $paths[] = $extra;
                }
            }
        }
        return $paths;
    }

    /**
     * Converts an absolute path inside $root to a $root-relative
     * path for git.
     */
    private function relative(string $root, string $path): string
    {
        $rootLen = strlen($root) + 1;
        return substr($path, $rootLen);
    }

    /**
     * Runs git with the given args using proc_open. Returns true on
     * exit code zero. On failure, stderr is surfaced through the
     * output helper.
     *
     * @param list<string> $args
     */
    private function runGit(array $args, bool $verbose): bool
    {
        $result = $this->runGettext('git', $args);
        if ($verbose) {
            $this->output->info($result['command']);
        }
        if ($result['exitCode'] !== 0) {
            $this->output->error(sprintf('git failed with exit code %d.', $result['exitCode']));
            if ($result['stderr'] !== '') {
                $this->output->error($result['stderr']);
            }
            $this->output->error('Command: ' . $result['command']);
            return false;
        }
        return true;
    }

    /**
     * True when `git diff --cached --quiet` reports staged changes
     * (exit code 1). Any other outcome (0 = clean, >1 = error) means
     * skip the commit.
     */
    private function hasStagedChanges(string $repoPath, bool $verbose): bool
    {
        $result = $this->runGettext('git', ['-C', $repoPath, 'diff', '--cached', '--quiet']);
        if ($verbose) {
            $this->output->info($result['command']);
        }
        return $result['exitCode'] === 1;
    }
}
