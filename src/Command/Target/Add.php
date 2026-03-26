<?php

declare(strict_types=1);

namespace Horde\Hordectl\Command\Target;

use Exception;
use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\ConfigManager;
use Horde\Hordectl\Exception\TargetAlreadyExistsException;
use Horde\Hordectl\HordectlModuleTrait;
use Horde\Hordectl\Output;
use Horde\Hordectl\Target;
use Horde\Hordectl\TargetResolver;
use Horde\Hordectl\TargetType;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;

/**
 * Target add command
 *
 * Adds a new target with CLI or interactive mode.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Add implements Module
{
    use HordectlModuleTrait;

    protected HordeCli $cli;
    protected Output $output;
    protected Parser $parser;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance(HordeCli::class);
        $this->output = $dependencies->createOutput($this->cli);
        $this->parser = $dependencies->getInstance(Parser::class);
    }

    public function handle(array $argv = []): bool
    {
        if (count($argv) < 1 || $argv[0] !== 'add') {
            return false;
        }

        // Remove 'add' from argv
        array_shift($argv);

        // Parse arguments
        $this->parser->addOption(
            '--type',
            [
                'help' => 'Target type (local or remote)',
            ]
        );
        $this->parser->addOption(
            '--path',
            [
                'help' => 'Horde installation directory (for local targets)',
            ]
        );
        $this->parser->addOption(
            '--endpoint',
            [
                'help' => 'API endpoint URL (for remote targets, optional for local)',
            ]
        );
        $this->parser->addOption(
            '--secret',
            [
                'help' => 'Admin API secret (for remote targets, optional for local)',
                'default' => '',
            ]
        );
        $this->parser->addOption(
            '--description',
            [
                'help' => 'Target description',
                'default' => '',
            ]
        );
        $this->parser->addOption(
            '--verify-ssl',
            [
                'action' => 'store_true',
                'help' => 'Verify SSL certificates (default: true)',
                'default' => true,
            ]
        );

        // Allow options after positional arguments for this command
        $this->parser->allowInterspersedArgs = true;

        [$opts, $args] = $this->parser->parseArgs($argv);

        // Get target name (first positional arg, or auto-generate)
        $name = $args[0] ?? null;
        $type = $opts->type ?? null;

        // If missing critical info, show usage
        if ($type === null) {
            $this->showUsage();
            return true;
        }

        // Validate type
        if (!in_array($type, ['local', 'remote'])) {
            $this->cli->fatal("Invalid type '{$type}'. Must be 'local' or 'remote'.");
            return false;
        }

        // Auto-generate name if not provided
        if ($name === null) {
            $name = $this->generateTargetName($type, (array) $opts);
        }

        $config = new ConfigManager();
        $resolver = new TargetResolver();

        // Check if target already exists
        if ($resolver->targetExists($config, $name)) {
            throw new TargetAlreadyExistsException(
                "Target '{$name}' already exists. Use 'hordectl target update {$name}' to modify it."
            );
        }

        // Create target based on type
        try {
            if ($type === 'local') {
                $target = $this->createLocalTarget($name, (array) $opts);
            } else {
                $target = $this->createRemoteTarget($name, (array) $opts);
            }

            $resolver->saveTarget($config, $target);

            $this->cli->writeln(sprintf(
                "Target '%s' added successfully.",
                $name
            ));

            // Set as current if no current target
            if ($config->get('current-target') === null) {
                $resolver->setCurrentTarget($config, $name);
                $this->cli->writeln("Set as current target.");
            }

        } catch (Exception $e) {
            $this->cli->fatal($e->getMessage());
        }

        return true;
    }

    protected function createLocalTarget(string $name, array $opts): Target
    {
        $path = $opts['path'] ?? null;

        if ($path === null) {
            $this->cli->fatal("Missing --path for local target. Usage: hordectl target add <name> --type=local --path=<dir>");
        }

        // Normalize path
        $path = rtrim($path, '/');

        // Determine horde_base (add /vendor/horde/horde if needed)
        $hordeBase = $path;
        if (!str_ends_with($path, '/vendor/horde/horde')) {
            $hordeBase = $path . '/vendor/horde/horde';
        }

        // Validate endpoint if provided
        $endpoint = $opts['endpoint'] ?? null;
        if ($endpoint !== null) {
            $validEndpoint = $this->validateEndpoint($endpoint);
            if ($validEndpoint === null) {
                $this->cli->fatal('Could not validate endpoint. Tested both ' . $endpoint . '/observability/readiness and ' . $endpoint . '/horde/observability/readiness');
                return null;
            }
            $endpoint = $validEndpoint;
            if ($validEndpoint !== $opts['endpoint']) {
                $this->cli->writeln();
                $this->output->info('Endpoint auto-corrected to: ' . $validEndpoint);
                $this->cli->writeln();
            }
        }

        return new Target(
            name: $name,
            type: TargetType::Local,
            hordeBase: $hordeBase,
            hordeInstallDir: $path,
            endpoint: $endpoint,
            adminSecret: $opts['secret'],
            verifySsl: $opts['verify_ssl'],
            description: $opts['description'] !== '' ? $opts['description'] : null
        );
    }

    protected function createRemoteTarget(string $name, array $opts): Target
    {
        $endpoint = $opts['endpoint'] ?? null;

        if ($endpoint === null) {
            $this->cli->fatal("Missing --endpoint for remote target. Usage: hordectl target add <name> --type=remote --endpoint=<url> --secret=<secret>");
        }

        if (empty($opts['secret'])) {
            $this->cli->fatal("Missing --secret for remote target. Use --secret=<value>");
        }

        // Validate endpoint
        $validEndpoint = $this->validateEndpoint($endpoint);
        if ($validEndpoint === null) {
            $this->cli->fatal('Could not validate endpoint. Tested both ' . $endpoint . '/observability/readiness and ' . $endpoint . '/horde/observability/readiness');
            return null;
        }
        $endpoint = $validEndpoint;
        if ($validEndpoint !== $opts['endpoint']) {
            $this->cli->writeln();
            $this->output->info('Endpoint auto-corrected to: ' . $validEndpoint);
            $this->cli->writeln();
        }

        return new Target(
            name: $name,
            type: TargetType::Remote,
            hordeBase: null,
            hordeInstallDir: null,
            endpoint: $endpoint,
            adminSecret: $opts['secret'],
            verifySsl: $opts['verify_ssl'],
            description: $opts['description'] !== '' ? $opts['description'] : null
        );
    }

    /**
     * Validate endpoint by testing observability/readiness route
     *
     * Tests both the literal URL and URL+"/horde"
     *
     * @param string $endpoint Base endpoint URL
     * @return string|null Valid endpoint or null if both failed
     */
    protected function validateEndpoint(string $endpoint): ?string
    {
        $endpoint = rtrim($endpoint, '/');
        $candidates = [
            $endpoint,
            $endpoint . '/horde'
        ];

        foreach ($candidates as $candidate) {
            $url = $candidate . '/observability/readiness';
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $response === '1') {
                return $candidate;
            }
        }

        return null;
    }

    protected function generateTargetName(string $type, array $opts): string
    {
        if ($type === 'local') {
            $path = $opts['path'] ?? '';
            $dirname = basename($path);
            // Sanitize dirname
            $sanitized = preg_replace('/[^a-z0-9_]/', '_', strtolower($dirname));
            return 'local_' . $sanitized;
        } else {
            $endpoint = $opts['endpoint'] ?? '';
            $hostname = parse_url($endpoint, PHP_URL_HOST);
            if ($hostname) {
                $parts = explode('.', $hostname);
                $subdomain = $parts[0];
                return 'remote_' . $subdomain;
            }
            return 'remote_' . uniqid();
        }
    }

    protected function showUsage(): void
    {
        $this->cli->writeln("Usage: hordectl target add [<name>] --type=<local|remote> [options]");
        $this->cli->writeln();
        $this->cli->writeln("For local targets:");
        $this->cli->writeln("  hordectl target add mydev --type=local --path=/var/www/horde-dev");
        $this->cli->writeln("  hordectl target add mydev --type=local --path=/var/www/horde-dev --endpoint=http://localhost/horde");
        $this->cli->writeln();
        $this->cli->writeln("For remote targets:");
        $this->cli->writeln("  hordectl target add staging --type=remote --endpoint=https://staging.example.com/horde --secret=abc123");
        $this->cli->writeln();
        $this->cli->writeln("Options:");
        $this->cli->writeln("  --type=<local|remote>     Target type (required)");
        $this->cli->writeln("  --path=<dir>              Horde install directory (required for local)");
        $this->cli->writeln("  --endpoint=<url>          API endpoint URL (required for remote, optional for local)");
        $this->cli->writeln("  --secret=<value>          Admin API secret (required for remote, optional for local)");
        $this->cli->writeln("  --description=<text>      Target description");
        $this->cli->writeln("  --verify-ssl              Verify SSL certificates (default: true)");
    }
}
