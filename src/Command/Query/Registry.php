<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category  Horde
 * @package   Hordectl
 * @author    Ralf Lang <ralf.lang@ralf-lang.de>
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Hordectl\Command\Query;

use Exception;
use Horde\Cli\Cli as HordeCli;
use Horde\Cli\Modular\Module;
use Horde\Cli\Modular\ModuleUsage;
use Horde\Hordectl\AdminApiClientTrait;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\Service\AdminApiClient;
use Horde\Hordectl\TargetCapabilityTrait;
use Horde\Injector\Injector;

/**
 * Query command module for the compiled registry.
 *
 * `hordectl query registry` retrieves the compiled registry from the
 * target Horde install and dumps it as YAML. The top-level keys are
 * `default` (the merged default registry) plus one key per compiled
 * vhost. Vhosts with no dedicated registry file do not appear.
 *
 * Companion to `hordectl query apps`. Where `apps` returns the
 * runtime application list, `registry` returns the configuration
 * inputs the registry loader would see, before merge.
 *
 * @category  Horde
 * @package   Hordectl
 * @author    Ralf Lang <ralf.lang@ralf-lang.de>
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Registry implements Module, ModuleUsage
{
    use ModuleTrait;
    use TargetCapabilityTrait;
    use AdminApiClientTrait;

    protected HordeCli $cli;
    protected Output $output;
    protected AdminApiClient $apiClient;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->output = $dependencies->createOutput($this->cli);
    }

    public function handle(array $argv = []): bool
    {
        if (count($argv) < 1) {
            return false;
        }

        if ($argv[0] !== 'registry') {
            return false;
        }

        $target = $this->requireApiCapability();
        $this->apiClient = $this->createApiClientFromTarget($target);

        try {
            $registry = $this->apiClient->getRegistry();

            $slots = $registry->toArray();
            if (empty($slots)) {
                $this->cli->writeln();
                $this->output->warn('Registry is empty');
                $this->cli->writeln();
                return true;
            }

            // Feed the shared YamlWriter so `query registry` dumps
            // under the same apps/resources/items envelope as
            // `query user`, `query apps`, and every other query
            // subcommand. `items` here holds a map (slot name to
            // per-app registry) rather than the more common list of
            // homogeneous rows. That's a small schema variance from
            // the other resources, but it matches the natural shape
            // the compiler produces and forcing a list wrapper would
            // just add noise.
            //
            // The leading-backslash string key is required — the
            // parent Query::handle() registers the writer under that
            // exact key, and the injector treats `::class` and the
            // leading-backslash form as separate cache slots.
            $writer = $this->dependencies->getInstance('\Horde\Hordectl\YamlWriter');
            $writer->addResource('builtin', 'registry', $slots);

            return true;
        } catch (Exception $e) {
            $this->cli->writeln();
            $this->output->error($e->getMessage());
            $this->cli->writeln();
            $this->cli->writeln('Unable to query registry via REST API.');
            $this->cli->writeln('Please check:');
            $this->cli->writeln('  - Admin API is enabled in Horde conf.php');
            $this->cli->writeln('  - admin_secret is configured in hordectl.php or Horde conf.php');
            $this->cli->writeln('  - Horde endpoint is accessible: ' . ($this->getEndpoint() ?? 'not configured'));
            $this->cli->writeln();
            return true;
        }
    }

    private function getEndpoint(): ?string
    {
        try {
            $configManager = new \Horde\Hordectl\ConfigManager();
            return $configManager->get('admin_api')['endpoint'] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    public function getUsage(): string
    {
        return 'Query compiled registry (default merge plus per-vhost deltas)';
    }

    public function getTitle(): string
    {
        return 'registry';
    }

    public function getUsageDescription(): array
    {
        return [
            'Query the compiled registry via REST API.',
            '',
            'Returns a YAML dump of the two-level compiled registry:',
            '',
            '  default:          # merged default registry (vendor + base + snippets + local)',
            '    horde: { ... }',
            '    imp: { ... }',
            '  foo.example.com:  # per-vhost delta relative to default',
            '    imp: { ... }',
            '  bar.example.com:',
            '    horde: { ... }',
            '',
            'Vhost files that do not exist on the target install do not appear',
            'in the output. A deployment with no vhost overrides returns just',
            'the `default` key.',
            '',
            'Usage:',
            '  hordectl query registry',
            '',
            'Requirements:',
            '  - Horde admin API must be enabled (admin_api.enabled = true)',
            '  - admin_secret must be configured',
            '  - Horde endpoint must be accessible',
        ];
    }
}
