<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Command\WebserverConfig;

use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;
use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;
use Horde\Hordectl\AdminApiClientTrait;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\Service\AdminApiClient;
use Horde\Hordectl\Service\WebserverConfig\AppMapBuilder;
use Horde\Hordectl\Service\WebserverConfig\ConfigWriter;
use Horde\Hordectl\Service\WebserverConfig\GenerationContext;
use Horde\Hordectl\Service\WebserverConfig\NginxEmitter;
use Horde\Hordectl\Service\WebserverConfig\ReadmeEmitter;
use Horde\Hordectl\Service\WebserverConfig\RenderHelper;
use Horde\Hordectl\Service\WebserverConfig\WriteReport;
use Horde\Hordectl\TargetCapabilityTrait;
use Horde\Injector\Injector;

/**
 * `hordectl webserver-config nginx`
 *
 * Thin command glue. Emits nginx server blocks under
 * var/webserver/nginx/. All rendering and I/O live in
 * Horde\Hordectl\Service\WebserverConfig\*.
 */
final class Nginx implements Module, ModuleUsage
{
    use ModuleTrait;
    use TargetCapabilityTrait;
    use AdminApiClientTrait;

    protected HordeCli $cli;
    protected Output $output;
    protected ?AdminApiClient $apiClient = null;

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
        return ['nginx'];
    }

    public function getBaseOptions(): array
    {
        return WebserverConfigOptions::common();
    }

    public function handle(array $argv = []): bool
    {
        if (empty($argv) || $argv[0] !== 'nginx') {
            return false;
        }
        $target = $this->requireFilesystemCapability();
        [$opts, $args] = $this->handleCommandline($argv);

        $render = new RenderHelper();
        $map = WebserverConfigOptions::resolveMap(
            $opts,
            fn () => $this->apiClient ??= $this->createApiClientFromTarget($target),
            new AppMapBuilder(),
            $this->output,
        );
        if (!$map->ok) {
            $this->output->error($map->error);
            return true;
        }
        foreach ($map->warnings as $warning) {
            $this->output->warn($warning);
        }

        $outputDir = (string) ($opts->output_dir
            ?? ($target->hordeInstallDir . '/var/webserver/nginx'));
        $force = (bool) ($opts->force ?? false);
        $phpHandler = (string) ($opts->php_handler ?? $render->defaultPhpHandler());
        $prefix = (string) ($opts->nginx_prefix ?? '/etc/nginx');

        $entries = (new NginxEmitter($render))->emit($map, $outputDir, $phpHandler, $prefix);
        $context = new GenerationContext(
            tier: WebserverConfigOptions::classifyTier($opts),
            flavor: 'nginx',
            flags: WebserverConfigOptions::extractRelevantFlags($opts),
        );
        $entries[] = (new ReadmeEmitter())->emit($context, $outputDir, $prefix);
        $report = (new ConfigWriter())->write($entries, $force);

        $this->reportOutcome($report);
        return true;
    }

    private function reportOutcome(WriteReport $report): void
    {
        foreach ($report->written as $path) {
            $this->output->ok(sprintf('  %s', $path));
        }
        foreach ($report->skipped as $path) {
            $this->output->info(sprintf('  exists, skipping (use --force): %s', $path));
        }
        foreach ($report->sketched as $path) {
            $this->output->info(sprintf('  operator-owned, preserved: %s', $path));
        }
        foreach ($report->failed as $path) {
            $this->output->error(sprintf('  failed: %s', $path));
        }
        $this->output->info(sprintf(
            'Wrote %d file(s), skipped %d, preserved %d sketch(es), failed %d.',
            count($report->written),
            count($report->skipped),
            count($report->sketched),
            count($report->failed),
        ));
    }
}
