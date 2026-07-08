<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Command\WebserverConfig;

use Horde\Argv\Option;
use Horde\Hordectl\Output;
use Horde\Hordectl\Service\WebserverConfig\AppMapBuilder;
use Horde\Hordectl\Service\WebserverConfig\MapBuildResult;

/**
 * Shared option definitions and input-tier resolution for every
 * webserver-config flavor command class.
 *
 * Not a service in the pure sense; sits in the command layer because
 * it knows about argv-parser Option objects and MapBuildResult
 * dispatch. Kept out of individual flavor classes so option shape
 * stays uniform across flavors and the "which tier wins" rule lives
 * in one place.
 */
final class WebserverConfigOptions
{
    /**
     * Common option definitions accepted by every flavor.
     *
     * @return list<Option>
     */
    public static function common(): array
    {
        return [
            new Option('--output-dir', ['action' => 'store', 'type' => 'string',
                'help' => 'Where to write. Default: <install>/var/webserver/<flavor>/']),
            new Option('--registry-in', ['action' => 'store', 'type' => 'string',
                'help' => 'Read registry YAML from stdin with `--registry-in=-` (same shape as `hordectl query registry`)']),
            new Option('--default-url', ['action' => 'store', 'type' => 'string',
                'help' => 'Vanilla-layout base URL (bootstrap tier)']),
            new Option('--root-bundle-path', ['action' => 'store', 'type' => 'string',
                'help' => 'Filesystem path to the bundle root (bootstrap tier)']),
            new Option('--app-webroots', ['action' => 'store', 'type' => 'string',
                'help' => 'Per-app overrides: `imp|https://webmail.example.org,...`']),
            new Option('--php-handler', ['action' => 'store', 'type' => 'string',
                'help' => 'php-fpm target: `unix-socket:/run/php/php8.3-fpm.sock` or `tcp:127.0.0.1:9000`']),
            new Option('--apache-serverroot', ['action' => 'store', 'type' => 'string',
                'help' => 'Apache ServerRoot the operator will drop horde-includes/ under. Default: /etc/apache2 (Debian/Ubuntu/SUSE); RHEL/Fedora is /etc/httpd']),
            new Option('--nginx-prefix', ['action' => 'store', 'type' => 'string',
                'help' => 'nginx prefix the operator will drop horde-includes/ under. Default: /etc/nginx']),
            new Option('--force', ['action' => 'store_true',
                'help' => 'Overwrite existing files (except operator-owned tls sketches)']),
            new Option('-v', '--verbose', ['action' => 'store_true',
                'help' => 'Print each emitted file']),
        ];
    }

    /**
     * Resolves argv options into a MapBuildResult. Runs the three
     * input tiers in order, first success wins. Never touches
     * filesystem outside the builder.
     *
     * @param callable $apiClientProvider Called (lazily) when tier 1 is used.
     *                                    Returns an AdminApiClient or null.
     */
    public static function resolveMap(
        object $opts,
        callable $apiClientProvider,
        AppMapBuilder $builder,
        Output $output,
    ): MapBuildResult {
        // Tier 3 wins when --root-bundle-path is provided (with or
        // without --default-url). Its whole point is bootstrapping
        // ahead of a running registry; consumes --default-url and
        // --app-webroots inline so no post-override pass is needed.
        if (!empty($opts->root_bundle_path)) {
            return $builder->fromDefaults(
                (string) ($opts->default_url ?? ''),
                (string) $opts->root_bundle_path,
                (string) ($opts->app_webroots ?? ''),
            );
        }
        // Tier 2: stdin YAML. Explicit opt-in via `--registry-in=-`.
        // Consumes the same envelope shape `hordectl query registry`
        // emits so pipe composition works naturally.
        if (($opts->registry_in ?? '') === '-') {
            $raw = stream_get_contents(STDIN);
            if ($raw === false) {
                $raw = '';
            }
            $map = $builder->fromStdinYaml((string) $raw);
        } else {
            // Tier 1: live registry via the admin API.
            $client = $apiClientProvider();
            if ($client === null) {
                return MapBuildResult::failure(
                    'No admin API client available. Pass --registry-in=- or --default-url + --root-bundle-path instead.',
                );
            }
            $map = $builder->fromLiveRegistry($client);
        }

        // Overrides layer on top of tier-1 and tier-2 results.
        // --app-webroots replaces webroots per app id;
        // --default-url promotes any remaining relative webroots to
        // absolute ones anchored at the supplied host. Tier 3 has
        // already consumed both flags inline; we skip this pass for
        // that branch (returned early above).
        return $builder->applyOverrides(
            $map,
            (string) ($opts->app_webroots ?? ''),
            (string) ($opts->default_url ?? ''),
        );
    }

    /**
     * Classifies which input tier the same $opts object would resolve
     * to. Used by the command glue to build a GenerationContext for
     * the README emitter without duplicating the branch order.
     *
     * @return 'defaults'|'stdin-yaml'|'live-registry'
     */
    public static function classifyTier(object $opts): string
    {
        if (!empty($opts->root_bundle_path)) {
            return 'defaults';
        }
        if (($opts->registry_in ?? '') === '-') {
            return 'stdin-yaml';
        }
        return 'live-registry';
    }

    /**
     * Extracts the flags that shaped this run, keyed by their long
     * option name. Used by GenerationContext::reproducer() to build a
     * copy-paste-safe command. Only the flags a reproducer needs to
     * carry are included; --force / --verbose / --output-dir are
     * omitted because they do not affect the config's content.
     *
     * @return array<string, string|bool>
     */
    public static function extractRelevantFlags(object $opts): array
    {
        $flags = [];
        $keep = [
            '--registry-in' => 'registry_in',
            '--default-url' => 'default_url',
            '--root-bundle-path' => 'root_bundle_path',
            '--app-webroots' => 'app_webroots',
            '--php-handler' => 'php_handler',
            '--apache-serverroot' => 'apache_serverroot',
            '--nginx-prefix' => 'nginx_prefix',
        ];
        foreach ($keep as $flag => $property) {
            $value = $opts->{$property} ?? null;
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            $flags[$flag] = $value;
        }
        return $flags;
    }
}
