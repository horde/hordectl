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
use Horde\Injector\Injector;

/**
 * hordectl translation binaries
 *
 * Reports which gettext tools the translation subcommand can find
 * on PATH plus their versions. Diagnostic-only. Never mutates
 * anything. Emit this before opening a bug about "extract does not
 * work" so we know whether the toolchain is even present.
 *
 * Also useful as a first step during CI setup: run it once, confirm
 * every tool the CI job needs is available, then move on to the
 * actual translation checks.
 */
final class Binaries implements Module, ModuleUsage
{
    use ModuleTrait;
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
        return ['binaries'];
    }

    public function getBaseOptions(): array
    {
        return [
            new Option('--strict', ['action' => 'store_true',
                'help' => 'Emit an error when any binary is missing (for CI gating)']),
        ];
    }

    public function handle(array $argv = []): bool
    {
        if (empty($argv) || $argv[0] !== 'binaries') {
            return false;
        }
        [$opts, $args] = $this->handleCommandline($argv);
        $strict = (bool) ($opts->strict ?? false);

        // gettext binaries plus the two non-gettext tools the tool
        // shells out to. Keep this list in sync with the actual set
        // of subcommands that call runGettext().
        $binaries = array_merge(
            array_keys($this->knownBinaries),
            ['diff', 'git'],
        );

        $rows = [];
        $missing = 0;
        foreach ($binaries as $binary) {
            $path = $this->locateBinary($binary);
            if ($path === null) {
                $missing++;
                $rows[] = [$binary, '(missing)', ''];
                continue;
            }
            $version = $this->probeVersion($path);
            $rows[] = [$binary, $path, $version];
        }

        $this->output->table(new Table(
            headers: ['Binary', 'Path', 'Version'],
            rows: $rows,
            alignments: ['left', 'left', 'left'],
            caption: 'gettext toolchain',
        ));

        if ($missing > 0) {
            $this->cli->writeln('');
            $this->output->error(sprintf(
                '%d tool%s missing.',
                $missing,
                $missing === 1 ? '' : 's',
            ));
            $this->output->error('Install the GNU gettext package to fix this:');
            $this->output->error('  Debian/Ubuntu:  apt install gettext');
            $this->output->error('  Fedora/RHEL:    dnf install gettext');
            $this->output->error('  openSUSE:       zypper install gettext-tools');
            $this->output->error('  Alpine:         apk add gettext');
            $this->output->error('  macOS (brew):   brew install gettext && brew link --force gettext');
            if ($strict) {
                $this->cli->fatal(sprintf('%d required tool%s missing.', $missing, $missing === 1 ? '' : 's'));
            }
        } else {
            $this->cli->writeln('');
            $this->output->ok('All required tools found.');
        }
        return true;
    }

    /**
     * Runs `<binary> --version` and returns the first line of
     * stdout. Empty when the tool doesn't accept --version.
     */
    private function probeVersion(string $path): string
    {
        $result = $this->runGettext($path, ['--version']);
        if ($result['exitCode'] !== 0) {
            return '';
        }
        $lines = preg_split('/\R/', $result['stdout']);
        if ($lines === false || $lines === []) {
            return '';
        }
        return trim($lines[0]);
    }
}
