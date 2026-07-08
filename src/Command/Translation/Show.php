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
 * hordectl translation show
 *
 * Loads a compiled .mo for a given (package, locale) pair and prints
 * every msgid together with the translation the runtime will see.
 *
 * Default mode parses the .mo binary directly. This is portable: it
 * works whether or not the host has the locale installed via
 * locale-gen or the PHP gettext extension loaded.
 *
 * With --via-gettext, the command switches to the actual runtime
 * path: bindtextdomain / textdomain / gettext() calls. That exercises
 * libintl end-to-end and surfaces host-locale misconfiguration, but
 * requires the gettext extension and the locale to be present in the
 * OS. Useful for reproducing "translations aren't loading in
 * production" bug reports.
 *
 * Neither mode mutates anything.
 */
final class Show implements Module, ModuleUsage
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
        return ['show'];
    }

    public function getBaseOptions(): array
    {
        return [
            new Option('--locale', ['action' => 'store', 'type' => 'string',
                'help' => 'Locale whose .mo should be loaded (required)']),
            new Option('--package', ['action' => 'store', 'type' => 'string',
                'help' => 'Restrict to a single package id (required)']),
            new Option('--module', ['action' => 'store', 'type' => 'string',
                'help' => 'Legacy alias for --package']),
            new Option('--source', ['action' => 'store', 'type' => 'string',
                'help' => 'Package discovery source: composer (default) or repos']),
            new Option('--filter', ['action' => 'store', 'type' => 'string',
                'help' => 'Show only msgids whose text matches this substring (case-insensitive)']),
            new Option('--limit', ['action' => 'store', 'type' => 'int',
                'help' => 'Truncate output after this many entries']),
            new Option('--via-gettext', ['action' => 'store_true',
                'help' => 'Exercise the runtime gettext() path instead of parsing the .mo directly']),
            new Option('--untranslated-only', ['action' => 'store_true',
                'help' => 'Show only entries where the translation equals the msgid or is empty']),
        ];
    }

    public function handle(array $argv = []): bool
    {
        if (empty($argv) || $argv[0] !== 'show') {
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
        $packageFilter = $opts->package ?? $opts->module ?? null;
        if (empty($packageFilter)) {
            $this->output->error('--package is required.');
            return true;
        }

        $source = (string) ($opts->source ?? 'composer');
        $viaGettext = (bool) ($opts->via_gettext ?? false);
        $filter = $opts->filter ?? null;
        $limit = $opts->limit ?? null;
        $untranslatedOnly = (bool) ($opts->untranslated_only ?? false);

        $packages = $this->filterByPackage(
            $this->discoverPackages($installDir, $source),
            $packageFilter,
        );
        if ($packages === []) {
            $this->output->warn(sprintf('Package %s not found.', $packageFilter));
            return true;
        }
        $package = $packages[0];

        $moPath = $package->moFile($locale);
        if (!is_file($moPath)) {
            $this->output->error(sprintf(
                'No compiled .mo for %s (%s). Expected %s. Run `translation compile --locale=%s --package=%s` first.',
                $package->id,
                $locale,
                $moPath,
                $locale,
                $package->id,
            ));
            return true;
        }

        $this->output->info(sprintf(
            'Loading %s (%s, domain=%s) from %s',
            $package->id,
            $locale,
            $package->effectiveDomain(),
            $moPath,
        ));

        $entries = $viaGettext
            ? $this->readViaGettext($package, $locale, $moPath)
            : $this->readMoFile($moPath);

        if ($entries === null) {
            return true;
        }

        // Apply filters BEFORE limit so --limit=N always returns the
        // first N post-filter rows, not a scan of the first N raw rows.
        if ($filter !== null && $filter !== '') {
            $needle = mb_strtolower($filter);
            $entries = array_values(array_filter(
                $entries,
                static fn($e) => str_contains(mb_strtolower($e['msgid']), $needle)
                    || str_contains(mb_strtolower($e['msgstr']), $needle),
            ));
        }
        if ($untranslatedOnly) {
            $entries = array_values(array_filter(
                $entries,
                static fn($e) => $e['msgstr'] === '' || $e['msgstr'] === $e['msgid'],
            ));
        }

        $total = count($entries);
        if ($limit !== null && $limit > 0 && $total > $limit) {
            $entries = array_slice($entries, 0, $limit);
        }

        if ($entries === []) {
            $this->output->info('No entries match.');
            return true;
        }

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                $this->truncate($entry['msgid']),
                $this->truncate($entry['msgstr']),
            ];
        }
        $this->cli->writeln('');
        $this->output->table(new Table(
            headers: ['msgid', 'msgstr'],
            rows: $rows,
            alignments: ['left', 'left'],
            caption: sprintf(
                '%s / %s / %s   (showing %d of %d)',
                $package->id,
                $locale,
                $package->effectiveDomain(),
                count($entries),
                $total,
            ),
        ));
        return true;
    }

    /**
     * Truncates a cell value for the table display. Individual
     * entries can be pages long (help text, verbose error messages);
     * clip them so the table stays readable.
     */
    private function truncate(string $value, int $max = 80): string
    {
        $value = str_replace(["\r\n", "\n", "\r"], ' | ', $value);
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max - 1) . '…';
    }

    /**
     * Parses a GNU MO file and returns a list of
     * [msgid, msgstr] entries. Skips the header entry (empty msgid)
     * so it doesn't clutter the table.
     *
     * MO layout: 4-byte magic, revision (major/minor), string count,
     * originals offset, translations offset, plus (count) triples of
     * (length, offset) each. Little-endian by default with magic
     * 0x950412de. Big-endian variant has magic 0xde120495 and needs
     * an N unpack.
     *
     * @return list<array{msgid: string, msgstr: string}>|null
     */
    private function readMoFile(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || strlen($raw) < 28) {
            $this->output->error(sprintf('Could not read .mo file %s.', $path));
            return null;
        }
        $magic = unpack('V1', substr($raw, 0, 4));
        if ($magic === false) {
            $this->output->error('Corrupt .mo header.');
            return null;
        }
        $magic = $magic[1];
        // 0x950412de = 2500072158, but the sign-agnostic comparison is
        // sturdier across 32/64-bit PHP so we compare with hexdec.
        if ($magic === 0x950412de) {
            $endian = 'V'; // little
        } elseif ($magic === 0xde120495) {
            $endian = 'N'; // big
        } else {
            $this->output->error(sprintf('Not a GNU .mo file (bad magic 0x%08x).', $magic));
            return null;
        }

        $header = unpack($endian . '6', substr($raw, 4, 24));
        if ($header === false) {
            $this->output->error('Corrupt .mo header.');
            return null;
        }
        // 1: revision, 2: nstrings, 3: origOffset, 4: transOffset,
        // 5: hashSize (ignored), 6: hashOffset (ignored).
        $n = $header[2];
        $origOff = $header[3];
        $transOff = $header[4];

        $entries = [];
        for ($i = 0; $i < $n; $i++) {
            $origMeta = unpack($endian . '2', substr($raw, $origOff + $i * 8, 8));
            $transMeta = unpack($endian . '2', substr($raw, $transOff + $i * 8, 8));
            if ($origMeta === false || $transMeta === false) {
                continue;
            }
            $msgid = substr($raw, $origMeta[2], $origMeta[1]);
            $msgstr = substr($raw, $transMeta[2], $transMeta[1]);
            // Plural entries: msgid contains msgid_singular \0 msgid_plural,
            // msgstr contains msgstr[0] \0 msgstr[1] \0 ... We show
            // the singular form only. A future --show-plurals flag
            // could expand this.
            if (str_contains($msgid, "\0")) {
                $msgid = strstr($msgid, "\0", true) ?: $msgid;
            }
            if (str_contains($msgstr, "\0")) {
                $msgstr = strstr($msgstr, "\0", true) ?: $msgstr;
            }
            // The empty-msgid entry is the metadata header. Skip it.
            if ($msgid === '') {
                continue;
            }
            $entries[] = ['msgid' => $msgid, 'msgstr' => $msgstr];
        }
        return $entries;
    }

    /**
     * Reads the msgid list from the .mo (so we know what to look
     * up), then rebinds the domain via PHP's gettext extension and
     * calls gettext() on each msgid. Result reflects what the
     * running app would actually see, including host-locale plumbing.
     *
     * @return list<array{msgid: string, msgstr: string}>|null
     */
    private function readViaGettext(Package $package, string $locale, string $moPath): ?array
    {
        if (!function_exists('bindtextdomain') || !function_exists('gettext')) {
            $this->output->error('The PHP gettext extension is not loaded. Install php-gettext (or equivalent) or drop --via-gettext.');
            return null;
        }
        // Get the msgid inventory from the mo itself. We can't ask
        // gettext for a list of keys.
        $entries = $this->readMoFile($moPath);
        if ($entries === null) {
            return null;
        }
        // bindtextdomain expects <dir>/<locale>/LC_MESSAGES/<domain>.mo.
        // The path we already have is at that exact layout, so the
        // base dir is $package->localeDir().
        $domain = $package->effectiveDomain();
        $bindDir = $package->localeDir();

        // Rebind explicitly in this process. We're not persisting the
        // change anywhere. It dies with the CLI process.
        bindtextdomain($domain, $bindDir);
        bind_textdomain_codeset($domain, 'UTF-8');
        textdomain($domain);

        // Best-effort locale activation. Try the exact requested
        // form first, then progressively longer variants that the
        // OS commonly ships (e.g. `de` -> `de_DE.UTF-8`). If nothing
        // works, libintl falls back to msgid. We surface that too.
        $candidates = [
            $locale . '.UTF-8',
            $locale . '.utf8',
            $locale,
        ];
        if (!str_contains($locale, '_')) {
            $upper = strtoupper($locale);
            $candidates = array_merge($candidates, [
                sprintf('%s_%s.UTF-8', $locale, $upper),
                sprintf('%s_%s.utf8', $locale, $upper),
                sprintf('%s_%s', $locale, $upper),
            ]);
        }
        $active = null;
        foreach ($candidates as $cand) {
            $result = setlocale(LC_MESSAGES, $cand);
            if ($result !== false) {
                $active = $result;
                break;
            }
        }
        if ($active === null) {
            $this->output->warn(sprintf(
                'setlocale() rejected every candidate for %s. On glibc systems, generate it with `locale-gen %s.UTF-8`. Falling back to whatever the process inherited.',
                $locale,
                $locale,
            ));
        } else {
            $this->output->info(sprintf('Active locale: %s', $active));
        }
        putenv('LANGUAGE=' . $locale);
        putenv('LC_ALL=' . ($active ?? $locale));

        $out = [];
        foreach ($entries as $entry) {
            $out[] = [
                'msgid' => $entry['msgid'],
                'msgstr' => dgettext($domain, $entry['msgid']),
            ];
        }
        return $out;
    }
}
