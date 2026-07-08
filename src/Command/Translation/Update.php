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
 * hordectl translation update
 *
 * Convenience wrapper: runs `extract` then `merge` for the requested
 * locale. Does NOT run cleanup or compile. Translators typically want
 * `update` to refresh the po from source without touching drafts.
 *
 * This subcommand does not accept a --dry-run flag of its own. Each
 * inner call is delegated to the extract/merge command and picks up
 * whatever flags the user passes explicitly to update.
 */
final class Update implements Module, ModuleUsage
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
        return ['update'];
    }

    public function getBaseOptions(): array
    {
        return [
            new Option('--locale', ['action' => 'store', 'type' => 'string',
                'help' => 'Locale to update (required)']),
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
        if (empty($argv) || $argv[0] !== 'update') {
            return false;
        }
        [$opts, $args] = $this->handleCommandline($argv);

        $locale = (string) ($opts->locale ?? '');
        if ($locale === '') {
            $this->output->error('--locale is required.');
            return true;
        }

        // Rebuild the argv fragments to hand to Extract and Merge.
        $shared = ['--source=' . ($opts->source ?? 'composer')];
        if (!empty($opts->package)) {
            $shared[] = '--package=' . $opts->package;
        } elseif (!empty($opts->module)) {
            $shared[] = '--package=' . $opts->module;
        }
        if (!empty($opts->verbose)) {
            $shared[] = '-v';
        }
        if (!empty($opts->dry_run)) {
            $shared[] = '--dry-run';
        }

        $this->output->info('=> extract');
        $extract = $this->dependencies->getInstance(Extract::class);
        $extract->handle(array_merge(['extract'], $shared));

        $this->output->info('=> merge');
        $merge = $this->dependencies->getInstance(Merge::class);
        $merge->handle(array_merge(['merge', '--locale=' . $locale], $shared));

        return true;
    }
}
