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
 * hordectl translation diff
 *
 * Compares two revisions or paths of a package's translation files
 * after normalization, so reviewers and CI can tell whether a change
 * is material (msgids or msgstrs) or cosmetic (creation-date-only).
 *
 * Two invocation shapes:
 *
 *   hordectl translation diff <ref-a> <ref-b> --package=imp [--locale=de]
 *     Diffs the file(s) at git ref A against git ref B in the
 *     package's working tree. Requires --source=repos.
 *
 *   hordectl translation diff --left=<path> --right=<path>
 *     Diffs two arbitrary files. Handy for scripts.
 *
 * Exit codes:
 *   0 = no material change
 *   1 = material change (diff printed to stdout)
 *   2 = error
 *
 * CI uses this as a gate ("does this PR change any translation?")
 * without ever reading tool output. The `--pot-only` flag scopes
 * the answer to .pot alone (message set changed) so a pure msgstr
 * update is not considered material.
 */
final class Diff implements Module, ModuleUsage
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
        return ['diff'];
    }

    public function getBaseOptions(): array
    {
        return [
            new Option('--package', ['action' => 'store', 'type' => 'string',
                'help' => 'Restrict to a single package id (required for ref-mode)']),
            new Option('--module', ['action' => 'store', 'type' => 'string',
                'help' => 'Legacy alias for --package']),
            new Option('--locale', ['action' => 'store', 'type' => 'string',
                'help' => 'Locale to diff. Omit to diff only the .pot.']),
            new Option('--source', ['action' => 'store', 'type' => 'string',
                'help' => 'Package discovery source: repos (default) or composer']),
            new Option('--pot-only', ['action' => 'store_true',
                'help' => 'Diff only the .pot (ignore locale .po files)']),
            new Option('--ignore-refs', ['action' => 'store_true',
                'help' => 'Also strip #: reference lines before diffing']),
            new Option('--left', ['action' => 'store', 'type' => 'string',
                'help' => 'Left-side path (path-mode, skip git refs)']),
            new Option('--right', ['action' => 'store', 'type' => 'string',
                'help' => 'Right-side path (path-mode, skip git refs)']),
            new Option('--quiet', ['action' => 'store_true',
                'help' => 'Suppress the diff body. Keep only the exit code']),
        ];
    }

    public function handle(array $argv = []): bool
    {
        if (empty($argv) || $argv[0] !== 'diff') {
            return false;
        }
        [$opts, $args] = $this->handleCommandline($argv);

        if (!$this->requireBinaries(['msgcat', 'diff'])) {
            return true;
        }

        $ignoreRefs = (bool) ($opts->ignore_refs ?? false);
        $quiet = (bool) ($opts->quiet ?? false);

        // Path mode: two files, no git.
        if (!empty($opts->left) && !empty($opts->right)) {
            $left = $this->normalizePo((string) $opts->left, $ignoreRefs);
            $right = $this->normalizePo((string) $opts->right, $ignoreRefs);
            return $this->emitDiff($left, $right, (string) $opts->left, (string) $opts->right, $quiet);
        }

        // Ref mode: two git refs against the working tree.
        if (count($args) !== 2) {
            $this->output->error('diff needs either --left/--right paths, or two positional git refs.');
            return true;
        }
        $target = $this->requireFilesystemCapability();
        $installDir = $target->hordeInstallDir;
        if (!$installDir) {
            $this->cli->fatal("Target '{$target->name}' has no installation directory configured.");
        }

        $source = (string) ($opts->source ?? 'composer');
        $packageFilter = $opts->package ?? $opts->module ?? null;
        if (empty($packageFilter)) {
            $this->output->error('--package is required for ref-mode diff.');
            return true;
        }

        $packages = $this->filterByPackage(
            $this->discoverPackages($installDir, $source),
            $packageFilter,
        );
        if ($packages === []) {
            $this->output->warn(sprintf('Package %s not found.', $packageFilter));
            return true;
        }
        $package = $packages[0];
        if (!is_dir($package->path . '/.git')) {
            $this->output->error(sprintf('Package %s is not a git working tree.', $package->id));
            return true;
        }

        [$refA, $refB] = $args;
        $locale = $opts->locale ?? null;
        $potOnly = (bool) ($opts->pot_only ?? false);

        $files = $this->targetFilesInPackage($package, $locale, $potOnly);
        $materialAny = false;
        foreach ($files as $relPath) {
            $leftText = $this->fileAtRef($package->path, $refA, $relPath);
            $rightText = $this->fileAtRef($package->path, $refB, $relPath);
            if ($leftText === null && $rightText === null) {
                continue;
            }
            $leftNorm = $this->normalizePoContent($leftText ?? '', $ignoreRefs);
            $rightNorm = $this->normalizePoContent($rightText ?? '', $ignoreRefs);
            $material = $this->emitDiff(
                $leftNorm,
                $rightNorm,
                sprintf('%s@%s:%s', $package->id, $refA, $relPath),
                sprintf('%s@%s:%s', $package->id, $refB, $relPath),
                $quiet,
            );
            $materialAny = $materialAny || $material;
        }
        // Convey material change via output convention. The shell
        // exit code is left to the top-level launcher.
        if (!$materialAny) {
            $this->output->ok('No material translation change.');
        }
        return true;
    }

    /**
     * Chooses the set of package-relative files to compare.
     *
     * @return list<string>
     */
    private function targetFilesInPackage(Package $package, ?string $locale, bool $potOnly): array
    {
        $files = [];
        // pot is always in scope.
        $files[] = 'locale/' . $package->effectiveDomain() . '.pot';
        if ($potOnly) {
            return $files;
        }
        if ($locale !== null && $locale !== '') {
            $files[] = 'locale/' . $locale . '/LC_MESSAGES/' . $package->effectiveDomain() . '.po';
        } else {
            foreach ($this->discoverLocales($package) as $entry) {
                if ($entry === 'en') {
                    continue;
                }
                $files[] = 'locale/' . $entry . '/LC_MESSAGES/' . $package->effectiveDomain() . '.po';
            }
        }
        return $files;
    }

    /**
     * `git show <ref>:<path>` for a single file, or a direct
     * filesystem read when $ref is the special string 'WORKTREE'.
     * Returns null when the file doesn't exist at that ref.
     */
    private function fileAtRef(string $repoPath, string $ref, string $relPath): ?string
    {
        if ($ref === 'WORKTREE' || $ref === 'WORK' || $ref === '.') {
            $abs = $repoPath . '/' . $relPath;
            return is_file($abs) ? (string) file_get_contents($abs) : null;
        }
        $spec = $ref . ':' . $relPath;
        $result = $this->runGettext('git', ['-C', $repoPath, 'show', $spec]);
        if ($result['exitCode'] !== 0) {
            return null;
        }
        return $result['stdout'];
    }

    /**
     * Prints the diff (unless --quiet) and returns whether the two
     * sides differ materially.
     */
    private function emitDiff(string $left, string $right, string $leftLabel, string $rightLabel, bool $quiet): bool
    {
        $diff = $this->unifiedDiff($left, $right, $leftLabel, $rightLabel);
        if ($diff === '') {
            return false;
        }
        if (!$quiet) {
            $this->cli->writeln($diff);
        }
        return true;
    }
}
