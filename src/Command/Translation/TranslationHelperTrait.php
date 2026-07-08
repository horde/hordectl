<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Command\Translation;

use Horde\Yaml\Yaml;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Shared logic for translation subcommands.
 *
 * Contents:
 *   - Package discovery (vendor/horde/* or ~/php/git/horde/*).
 *   - isTranslatable(): the ground-truth type filter.
 *   - runGettext(): every gettext-tool invocation goes through here,
 *                   with exit-code checking. Fixes functional issue #4
 *                   from the improvements doc.
 *   - Locale discovery per package.
 *
 * @see ~/php/horde-development/tools/hordectl/hordectl-translation-subcommand-plan-2026-07-08.md
 */
trait TranslationHelperTrait
{
    /**
     * Types considered translatable. Everything else is skipped
     * during discovery.
     *
     * @var list<string>
     */
    private array $translatableTypes = ['application', 'horde-library', 'library'];

    /**
     * Returns true when the given .horde.yml carries a translatable
     * type. Callers use this to filter discovery results.
     */
    private function isTranslatable(array $hordeYml): bool
    {
        $type = (string) ($hordeYml['type'] ?? '');
        return in_array($type, $this->translatableTypes, true);
    }

    /**
     * Discovers translatable packages under the given source root.
     *
     * $source is either 'composer' (scans <root>/vendor/horde/*) or
     * 'repos' (scans <root> itself as a dir of package checkouts).
     *
     * @return list<Package>
     */
    private function discoverPackages(string $root, string $source = 'composer'): array
    {
        $scanDir = $source === 'repos' ? $root : $root . '/vendor/horde';
        if (!is_dir($scanDir)) {
            return [];
        }
        $packages = [];
        foreach (scandir($scanDir) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '') {
                continue;
            }
            $path = $scanDir . '/' . $entry;
            if (!is_dir($path)) {
                continue;
            }
            $yml = $path . '/.horde.yml';
            if (!is_file($yml)) {
                continue;
            }
            try {
                $data = Yaml::loadFile($yml);
            } catch (Throwable $e) {
                continue;
            }
            if (!is_array($data) || !$this->isTranslatable($data)) {
                continue;
            }
            if (!is_dir($path . '/locale')) {
                continue;
            }
            $rawType = (string) $data['type'];
            $normalizedType = $rawType === 'horde-library' ? 'library' : $rawType;
            $id = (string) ($data['id'] ?? $entry);
            $domain = $this->discoverDomain($path, $id);
            $packages[] = new Package(
                id: $id,
                path: $path,
                type: $normalizedType,
                domain: $domain,
            );
        }
        return $packages;
    }

    /**
     * Sniffs the effective gettext text domain for a package.
     *
     * Preferred sources in order:
     *   1. An existing locale/<domain>.pot on disk (authoritative).
     *   2. The first locale/<locale>/LC_MESSAGES/<domain>.po file.
     *   3. Fallback to $id.
     *
     * This exists because horde/Exception and other legacy libraries
     * ship pot/po files as Horde_Exception.po even though their
     * .horde.yml id is 'Exception'. Recomputing the name from id
     * would strand the existing translations.
     */
    private function discoverDomain(string $packagePath, string $id): string
    {
        $localeDir = $packagePath . '/locale';
        if (!is_dir($localeDir)) {
            return $id;
        }
        foreach (glob($localeDir . '/*.pot') ?: [] as $pot) {
            return basename($pot, '.pot');
        }
        // Fall back to scanning the first LC_MESSAGES directory for a .po.
        foreach (scandir($localeDir) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '') {
                continue;
            }
            $msgDir = $localeDir . '/' . $entry . '/LC_MESSAGES';
            if (!is_dir($msgDir)) {
                continue;
            }
            foreach (glob($msgDir . '/*.po') ?: [] as $po) {
                return basename($po, '.po');
            }
        }
        return $id;
    }

    /**
     * Filters a package list to a single package by id. Returns the
     * whole list when $filter is null or empty.
     *
     * @param list<Package> $packages
     * @return list<Package>
     */
    private function filterByPackage(array $packages, ?string $filter): array
    {
        if ($filter === null || $filter === '') {
            return $packages;
        }
        return array_values(array_filter(
            $packages,
            fn(Package $p) => $p->id === $filter,
        ));
    }

    /**
     * The gettext binaries this trait invokes. Names match the
     * standard GNU gettext tools. Callers can preflight with
     * requireBinaries() to fail fast when a subcommand needs a
     * specific tool that isn't installed.
     *
     * @var array<string, string>  binary name => brief purpose
     */
    private array $knownBinaries = [
        'xgettext' => 'Extract translatable strings from source',
        'msgmerge' => 'Merge new pot into existing po',
        'msgfmt' => 'Compile po into mo',
        'msginit' => 'Bootstrap a new locale',
        'msgattrib' => 'Filter po entries (used by cleanup and compendium)',
        'msgcat' => 'Concatenate and normalize po files',
    ];

    /**
     * Returns the resolved filesystem path for $binary, or null when
     * PATH does not contain it. Never throws. The caller decides how
     * to react.
     */
    private function locateBinary(string $binary): ?string
    {
        $result = $this->runGettext('command', ['-v', $binary]);
        if ($result['exitCode'] === 0 && trim($result['stdout']) !== '') {
            return trim($result['stdout']);
        }
        // `command -v` isn't reliable when invoked via proc_open on
        // some shells. Fall back to `which` and a PATH walk.
        $result = $this->runGettext('which', [$binary]);
        if ($result['exitCode'] === 0 && trim($result['stdout']) !== '') {
            return trim($result['stdout']);
        }
        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        foreach ($paths as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Preflights a subcommand's tool dependencies. Emits an
     * actionable error and returns false when any required binary
     * is missing. Returns true when every binary in $binaries is
     * on PATH.
     *
     * Callers do:
     *   if (!$this->requireBinaries(['xgettext'])) { return true; }
     *
     * @param list<string> $binaries
     */
    private function requireBinaries(array $binaries): bool
    {
        $missing = [];
        foreach ($binaries as $binary) {
            if ($this->locateBinary($binary) === null) {
                $missing[] = $binary;
            }
        }
        if ($missing === []) {
            return true;
        }
        $this->output->error(sprintf(
            'Required gettext tool%s not found on PATH: %s',
            count($missing) === 1 ? '' : 's',
            implode(', ', $missing),
        ));
        $this->output->error('Install the GNU gettext package to fix this:');
        $this->output->error('  Debian/Ubuntu:  apt install gettext');
        $this->output->error('  Fedora/RHEL:    dnf install gettext');
        $this->output->error('  openSUSE:       zypper install gettext-tools');
        $this->output->error('  Alpine:         apk add gettext');
        $this->output->error('  macOS (brew):   brew install gettext && brew link --force gettext');
        $this->output->error('Then verify with `hordectl translation binaries`.');
        return false;
    }

    /**
     * Enumerates PHP source files under a package's tree that
     * xgettext should scan. Skips vendor/, *.local.php, and
     * *.d/ configuration directories.
     *
     * Symbolic links are followed so packages installed as
     * composer symlinks (the typical Horde dev layout) are
     * traversed correctly.
     *
     * The /vendor/ filter is intentionally applied against the
     * SUB-path (relative to the package root), so a package
     * installed at <install>/vendor/horde/<name>/ is not
     * short-circuited by the outer vendor segment.
     *
     * @return list<string>
     */
    private function collectSourceFiles(Package $package): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $package->path,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS,
            ),
        );
        $rootLen = strlen($package->path) + 1;
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (!preg_match('/\.(php|inc)$/', $path)) {
                continue;
            }
            $sub = substr($path, $rootLen);
            if (str_contains('/' . $sub, '/vendor/')) {
                continue;
            }
            if (str_contains($sub, '.local.php')) {
                continue;
            }
            if (preg_match('#/[^/]+\.d/#', '/' . $sub)) {
                continue;
            }
            $out[] = $path;
        }
        sort($out);
        return $out;
    }

    /**
     * Enumerates locales present under a package's locale/ directory.
     *
     * A locale directory is any subdir that contains LC_MESSAGES.
     * Returns e.g. ['de', 'fr', 'en'].
     *
     * @return list<string>
     */
    private function discoverLocales(Package $package): array
    {
        $localeDir = $package->localeDir();
        if (!is_dir($localeDir)) {
            return [];
        }
        $out = [];
        foreach (scandir($localeDir) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '') {
                continue;
            }
            if (is_dir($localeDir . '/' . $entry . '/LC_MESSAGES')) {
                $out[] = $entry;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Executes a gettext utility (msgmerge, msgattrib, msgfmt, ...)
     * and returns [stdout, stderr, exitCode] plus the shell command
     * used. Callers check exitCode. The trait never fails silently.
     *
     * @param list<string> $args  Positional and flag arguments to the binary.
     * @return array{stdout: string, stderr: string, exitCode: int, command: string}
     */
    private function runGettext(string $binary, array $args): array
    {
        $cmdParts = array_merge([escapeshellcmd($binary)], array_map('escapeshellarg', $args));
        $cmd = implode(' ', $cmdParts);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return [
                'stdout' => '',
                'stderr' => 'Failed to spawn ' . $binary,
                'exitCode' => -1,
                'command' => $cmd,
            ];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return [
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
            'exitCode' => $exit,
            'command' => $cmd,
        ];
    }

    /**
     * Convenience: run a gettext binary and hand control to the
     * caller on success (return true) or emit an error and return
     * false. Verbose mode prints the shell command before running.
     *
     * @param list<string> $args
     */
    private function runGettextChecked(string $binary, array $args, bool $verbose = false): bool
    {
        if ($verbose) {
            $preview = implode(' ', array_merge([$binary], $args));
            $this->output->info($preview);
        }
        $result = $this->runGettext($binary, $args);
        if ($result['exitCode'] !== 0) {
            $this->output->error(sprintf(
                '%s failed with exit code %d.',
                $binary,
                $result['exitCode'],
            ));
            if ($result['stderr'] !== '') {
                $this->output->error($result['stderr']);
            }
            $this->output->error('Command: ' . $result['command']);
            return false;
        }
        return true;
    }
}
