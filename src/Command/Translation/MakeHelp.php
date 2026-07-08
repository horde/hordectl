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

/**
 * hordectl translation make-help
 *
 * Marks reviewed help.xml entries as up-to-date. For every entry
 * still present in the English help.xml, the localized entry gets
 * a fresh md5 fingerprint and state="uptodate". English-source
 * comments left by update-help get stripped once the translator
 * has consumed them.
 *
 * Applications only.
 */
final class MakeHelp implements Module, ModuleUsage
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
        return ['make-help'];
    }

    public function getBaseOptions(): array
    {
        return [
            new Option('--locale', ['action' => 'store', 'type' => 'string',
                'help' => 'Locale to mark uptodate (default: every locale under the package)']),
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
        if (empty($argv) || $argv[0] !== 'make-help') {
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
                continue;
            }
            $docEn = new DOMDocument();
            if (!@$docEn->load($englishHelp)) {
                $this->output->error(sprintf('Could not parse %s', $englishHelp));
                continue;
            }
            $xpathEn = new DOMXPath($docEn);

            $locales = $localeFilter !== null
                ? [$localeFilter]
                : array_values(array_filter($this->discoverLocales($package), fn($l) => $l !== 'en'));

            foreach ($locales as $locale) {
                $row = $this->makeOne($package, $xpathEn, $locale, $verbose, $dryRun);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        if ($rows !== []) {
            $this->cli->writeln('');
            $this->output->table(new Table(
                headers: ['Application', 'Locale', 'Marked', 'Missing in English'],
                rows: $rows,
                alignments: ['left', 'left', 'right', 'right'],
                caption: 'help.xml make-uptodate summary',
            ));
        }
        return true;
    }

    /**
     * @return list<int|string>|null
     */
    private function makeOne(Package $package, DOMXPath $xpathEn, string $locale, bool $verbose, bool $dryRun): ?array
    {
        $localHelp = $package->helpXmlFile($locale);
        if (!is_file($localHelp)) {
            return null;
        }

        $docLoc = new DOMDocument();
        $docLoc->preserveWhiteSpace = true;
        if (!@$docLoc->load($localHelp)) {
            $this->output->error(sprintf('Could not parse %s', $localHelp));
            return null;
        }
        $docLoc->encoding = 'UTF-8';
        $docLoc->formatOutput = true;

        $marked = 0;
        $missing = 0;
        foreach ($docLoc->getElementsByTagName('entry') as $entry) {
            // Drop English-source comments left by update-help.
            $toRemove = [];
            foreach ($entry->childNodes as $child) {
                if ($child->nodeType === XML_COMMENT_NODE
                    && str_contains((string) $child->nodeValue, 'English entry')
                ) {
                    $toRemove[] = $child;
                }
            }
            foreach ($toRemove as $child) {
                $entry->removeChild($child);
            }

            $view = $entry->parentNode;
            while ($view instanceof DOMElement && $view->tagName !== 'view') {
                $view = $view->parentNode;
            }
            $entryId = $entry->getAttribute('id');
            $query = '//entry[@id="' . $entryId . '"]';
            if ($view instanceof DOMElement && $view->tagName === 'view') {
                $query = '//view[@id="' . $view->getAttribute('id') . '"]' . $query;
            }
            $list = $xpathEn->query($query);
            if ($list !== false && $list->length > 0) {
                $entry->setAttribute('md5', md5($list->item(0)->textContent));
                $entry->setAttribute('state', 'uptodate');
                $marked++;
            } else {
                $this->output->warn(sprintf(
                    '%s (%s): entry "%s" no longer in English help.',
                    $package->id,
                    $locale,
                    $entryId
                ));
                $missing++;
            }
        }

        if ($verbose) {
            $this->output->info(sprintf('Marking %s (%s): %d entries', $package->id, $locale, $marked));
        }
        if (!$dryRun) {
            $docLoc->save($localHelp);
        } else {
            $this->output->info(sprintf('  would write %s', $localHelp));
        }
        return [$package->id, $locale, $marked, $missing];
    }
}
