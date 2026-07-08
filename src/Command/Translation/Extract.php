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
 * hordectl translation extract
 *
 * Generate .pot template files by running xgettext across each
 * translatable package's source tree. Writes to
 * <package>/locale/<id>.pot by default. --output-dir redirects.
 */
final class Extract implements Module, ModuleUsage
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
        return ['extract'];
    }

    public function getBaseOptions(): array
    {
        return [
            new Option('--package', ['action' => 'store', 'type' => 'string',
                'help' => 'Restrict to a single package id']),
            new Option('--module', ['action' => 'store', 'type' => 'string',
                'help' => 'Legacy alias for --package']),
            new Option('--source', ['action' => 'store', 'type' => 'string',
                'help' => 'Package discovery source: composer (default) or repos']),
            new Option('--output-dir', ['action' => 'store', 'type' => 'string',
                'help' => 'Write .pot files to this directory instead of the package locale/']),
            new Option('--dry-run', ['action' => 'store_true',
                'help' => 'Print commands without executing them']),
            new Option('-v', '--verbose', ['action' => 'store_true',
                'help' => 'Print every gettext invocation before running']),
        ];
    }

    public function handle(array $argv = []): bool
    {
        if (empty($argv) || $argv[0] !== 'extract') {
            return false;
        }
        $target = $this->requireFilesystemCapability();
        [$opts, $args] = $this->handleCommandline($argv);

        if (!$this->requireBinaries(['xgettext'])) {
            return true;
        }

        $installDir = $target->hordeInstallDir;
        if (!$installDir) {
            $this->cli->fatal("Target '{$target->name}' has no installation directory configured.");
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

        foreach ($packages as $package) {
            $this->extractOne($package, $opts->output_dir ?? null, $verbose, $dryRun);
        }
        return true;
    }

    private function extractOne(Package $package, ?string $outputDir, bool $verbose, bool $dryRun): void
    {
        $this->output->info(sprintf('Extracting %s...', $package->id));
        $files = $this->collectSourceFiles($package);
        if ($files === []) {
            $this->output->warn(sprintf('  no source files found under %s', $package->path));
            return;
        }

        $potPath = $outputDir === null
            ? $package->potFile()
            : rtrim($outputDir, '/') . '/' . $package->effectiveDomain() . '.pot';
        $potDir = dirname($potPath);
        if (!is_dir($potDir)) {
            if ($dryRun) {
                $this->output->info(sprintf('  would mkdir %s', $potDir));
            } else {
                mkdir($potDir, 0o755, true);
            }
        }

        // Write source paths relative to the package root so the
        // resulting pot references files as "lib/Foo.php" rather than
        // an absolute path that leaks the caller's install dir.
        $rootLen = strlen($package->path) + 1;
        $relFiles = array_map(
            static fn(string $abs) => substr($abs, $rootLen),
            $files,
        );

        // xgettext reads a filelist from --files-from. Write it to a
        // sibling of the pot so cleanup on failure is simple.
        $listFile = $potPath . '.list';
        if (!$dryRun) {
            file_put_contents($listFile, implode("\n", $relFiles));
        }

        $args = [
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
        ];

        if ($dryRun) {
            $this->output->info('  xgettext ' . implode(' ', $args));
            return;
        }

        $ok = $this->runGettextChecked('xgettext', $args, $verbose);
        @unlink($listFile);
        if ($ok) {
            $this->output->ok(sprintf('  %s', $potPath));
        }
    }
}
