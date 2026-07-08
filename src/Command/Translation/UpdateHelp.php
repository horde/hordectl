<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Command\Translation;

use DOMDocument;
use DOMXPath;
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
use DOMElement;
use DateTimeImmutable;

/**
 * hordectl translation update-help
 *
 * Merges the English help.xml into each locale help.xml for every
 * translatable application. Libraries are skipped: they have no
 * help files.
 *
 * Behavior mirrors the legacy tool: entries in the localized file
 * are annotated with an English-source comment plus a `state`
 * attribute (new / changed / uptodate / unknown) so a translator
 * can find the work.
 *
 * Applications only: uses Package::isApplication() to filter.
 */
final class UpdateHelp implements Module, ModuleUsage
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
        return ['update-help'];
    }

    public function getBaseOptions(): array
    {
        return [
            new Option('--locale', ['action' => 'store', 'type' => 'string',
                'help' => 'Locale to update (default: every locale under the package)']),
            new Option('--package', ['action' => 'store', 'type' => 'string',
                'help' => 'Restrict to a single application id']),
            new Option('--module', ['action' => 'store', 'type' => 'string',
                'help' => 'Legacy alias for --package']),
            new Option('--source', ['action' => 'store', 'type' => 'string',
                'help' => 'Package discovery source: composer (default) or repos']),
            new Option('--dry-run', ['action' => 'store_true',
                'help' => 'Report intended changes without writing files']),
            new Option('-v', '--verbose', ['action' => 'store_true',
                'help' => 'Print every file touch']),
        ];
    }

    public function handle(array $argv = []): bool
    {
        if (empty($argv) || $argv[0] !== 'update-help') {
            return false;
        }
        $target = $this->requireFilesystemCapability();
        [$opts, $args] = $this->handleCommandline($argv);

        $installDir = $target->hordeInstallDir;
        if (!$installDir) {
            $this->cli->fatal("Target '{$target->name}' has no installation directory configured.");
        }

        $source = (string) ($opts->source ?? 'composer');
        $verbose = (bool) ($opts->verbose ?? false);
        $dryRun = (bool) ($opts->dry_run ?? false);
        $packageFilter = $opts->package ?? $opts->module ?? null;
        $localeFilter = $opts->locale ?? null;

        $packages = array_values(array_filter(
            $this->filterByPackage($this->discoverPackages($installDir, $source), $packageFilter),
            fn(Package $p) => $p->isApplication(),
        ));
        if ($packages === []) {
            $this->output->warn('No translatable applications found.');
            return true;
        }

        $rows = [];
        foreach ($packages as $package) {
            $englishHelp = $package->helpXmlFile('en');
            if (!is_file($englishHelp)) {
                $this->output->warn(sprintf(
                    '%s: no English help.xml at %s',
                    $package->id,
                    $englishHelp
                ));
                continue;
            }

            $locales = $localeFilter !== null
                ? [$localeFilter]
                : array_values(array_filter($this->discoverLocales($package), fn($l) => $l !== 'en'));

            foreach ($locales as $locale) {
                $row = $this->updateOne($package, $englishHelp, $locale, $verbose, $dryRun);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        if ($rows !== []) {
            $this->cli->writeln('');
            $this->output->table(new Table(
                headers: ['Application', 'Locale', 'Up-to-date', 'New', 'Changed', 'Unknown'],
                rows: $rows,
                alignments: ['left', 'left', 'right', 'right', 'right', 'right'],
                caption: 'help.xml update summary',
            ));
        }
        return true;
    }

    /**
     * Merges $englishHelp into $package->helpXmlFile($locale) using
     * the legacy tool's XPath-driven entry matching. Returns the
     * per-locale counts as a table row, or null on skip.
     *
     * @return list<int|string>|null
     */
    private function updateOne(Package $package, string $englishHelp, string $locale, bool $verbose, bool $dryRun): ?array
    {
        $localHelp = $package->helpXmlFile($locale);
        if (!is_file($localHelp)) {
            // No localized help yet: seed by copying the English one.
            $localDir = dirname($localHelp);
            if (!is_dir($localDir)) {
                if ($dryRun) {
                    $this->output->info(sprintf('  would mkdir %s', $localDir));
                } else {
                    mkdir($localDir, 0o755, true);
                }
            }
            if ($dryRun) {
                $this->output->info(sprintf('Would seed %s from %s', $localHelp, $englishHelp));
                return null;
            }
            if (!copy($englishHelp, $localHelp)) {
                $this->output->error(sprintf('Could not copy %s to %s', $englishHelp, $localHelp));
                return null;
            }
            $this->output->ok(sprintf('Seeded %s (%s) from English.', $package->id, $locale));
            return [$package->id, $locale, 0, 0, 0, 0];
        }

        $docEn = new DOMDocument();
        $docEn->preserveWhiteSpace = true;
        if (!@$docEn->load($englishHelp)) {
            $this->output->error(sprintf('Could not parse %s', $englishHelp));
            return null;
        }
        $docEn->encoding = 'UTF-8';
        $docEn->formatOutput = true;

        $docLoc = new DOMDocument();
        $docLoc->preserveWhiteSpace = true;
        if (!@$docLoc->load($localHelp)) {
            $this->output->error(sprintf('Could not parse %s', $localHelp));
            return null;
        }

        $uptodate = 0;
        $new = 0;
        $changed = 0;
        $unknown = 0;
        $date = (new DateTimeImmutable())->format('Y-m-d');
        $xpath = new DOMXPath($docLoc);

        foreach ($docEn->getElementsByTagName('entry') as $entry) {
            $view = $entry->parentNode;
            while ($view instanceof DOMElement && $view->tagName !== 'view') {
                $view = $view->parentNode;
            }
            $entryId = $entry->getAttribute('id');
            $query = '//entry[@id="' . $entryId . '"]';
            if ($view instanceof DOMElement && $view->tagName === 'view') {
                $query = '//view[@id="' . $view->getAttribute('id') . '"]' . $query;
            }
            $list = $xpath->query($query);
            if ($list !== false && $list->length > 0) {
                $entryLoc = $docEn->importNode($list->item(0), true);
                if ($entryLoc->hasAttribute('md5')
                    && md5($entry->textContent) !== $entryLoc->getAttribute('md5')
                ) {
                    $comment = $docEn->createComment(
                        " English entry ($date):\n"
                        . str_replace('--', '&#45;&#45;', $docEn->saveXML($entry)),
                    );
                    $entryLoc->appendChild($comment);
                    $entryLoc->setAttribute('state', 'changed');
                    $changed++;
                } elseif (!$entryLoc->hasAttribute('state')) {
                    $comment = $docEn->createComment(
                        " English entry ($date):\n"
                        . str_replace('--', '&#45;&#45;', $docEn->saveXML($entry)),
                    );
                    $entryLoc->appendChild($comment);
                    $entryLoc->setAttribute('state', 'unknown');
                    $unknown++;
                } else {
                    $uptodate++;
                }
            } else {
                $entryLoc = $docEn->importNode($entry, true);
                $entryLoc->setAttribute('state', 'new');
                $new++;
            }
            $entry->parentNode->replaceChild($entryLoc, $entry);
        }

        if ($verbose) {
            $this->output->info(sprintf('Updating %s (%s)', $package->id, $locale));
        }
        if (!$dryRun) {
            $docEn->save($localHelp);
        } else {
            $this->output->info(sprintf('  would write %s', $localHelp));
        }
        return [$package->id, $locale, $uptodate, $new, $changed, $unknown];
    }
}
