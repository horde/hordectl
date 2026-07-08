<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Service\WebserverConfig;

use Horde\Hordectl\Service\AdminApiClient;
use Horde\Yaml\Yaml;

/**
 * Resolves the three input tiers (live registry / stdin JSON /
 * vanilla-layout synthesis) into a normalized AppMap.
 *
 * Pure service. No CLI concerns, no Output writes. Errors surface as
 * MapBuildResult::$errors that the caller decides how to render.
 *
 * See ~/php/horde-development/tools/hordectl/hordectl-webserver-config-plan-2026-07-08.md
 * for the design.
 */
final class AppMapBuilder
{
    /** @var list<string> Standard bundle apps considered by the synthesis tier. */
    private array $defaultBundleApps = [
        'horde', 'imp', 'turba', 'kronolith', 'nag', 'mnemo', 'ingo',
        'jonah', 'chora', 'wicked', 'whups', 'gollem', 'passwd',
    ];

    /**
     * Tier 1: fetch the compiled registry via the admin API and
     * convert it to an AppMap.
     */
    public function fromLiveRegistry(AdminApiClient $client): MapBuildResult
    {
        try {
            $registry = $client->getRegistry();
        } catch (\Throwable $e) {
            return MapBuildResult::failure(sprintf(
                'Live registry unavailable: %s',
                $e->getMessage(),
            ));
        }
        return $this->fromRegistryArray($registry->toArray());
    }

    /**
     * Tier 2: parse stdin YAML into an AppMap. Accepts the same shape
     * `hordectl query registry` emits (the wrapped
     * apps.builtin.resources.registry.items envelope) as well as a
     * bare registry map at the top level, so power users can hand-craft
     * a payload.
     */
    public function fromStdinYaml(string $raw): MapBuildResult
    {
        if ($raw === '') {
            return MapBuildResult::failure('--registry-in=- but nothing arrived on stdin.');
        }
        try {
            $decoded = Yaml::load($raw);
        } catch (\Throwable $e) {
            return MapBuildResult::failure(sprintf(
                'stdin was not valid YAML: %s',
                $e->getMessage(),
            ));
        }
        if (!is_array($decoded)) {
            return MapBuildResult::failure('stdin YAML did not decode to a map.');
        }
        $slots = $decoded['apps']['builtin']['resources']['registry']['items']
            ?? $decoded['items']
            ?? $decoded;
        if (!is_array($slots)) {
            return MapBuildResult::failure('stdin YAML did not contain a recognizable registry map.');
        }
        return $this->fromRegistryArray($slots);
    }

    /**
     * Tier 3: synthesize an AppMap from the vanilla bundle layout.
     * Requires filesystem access to $rootBundlePath so we can
     * detect which apps are actually present.
     *
     * @param string $appWebrootsSpec Comma-separated `id|url` pairs.
     */
    public function fromDefaults(
        string $defaultUrl,
        string $rootBundlePath,
        string $appWebrootsSpec = '',
    ): MapBuildResult {
        $defaultUrl = rtrim($defaultUrl, '/');
        $rootBundlePath = rtrim($rootBundlePath, '/');
        $bundleWeb = $rootBundlePath . '/web';

        $overrides = [];
        $errors = [];
        if ($appWebrootsSpec !== '') {
            foreach (explode(',', $appWebrootsSpec) as $spec) {
                $spec = trim($spec);
                if ($spec === '') {
                    continue;
                }
                $parts = preg_split('/[|=]/', $spec, 2);
                if (!is_array($parts) || count($parts) !== 2) {
                    $errors[] = sprintf('Ignoring malformed --app-webroots entry: %s', $spec);
                    continue;
                }
                $overrides[trim($parts[0])] = trim($parts[1]);
            }
        }

        $apps = [];
        foreach ($this->defaultBundleApps as $id) {
            // Emit unconditionally. Tier 3 is a bootstrap tier: it must
            // produce a working config BEFORE the install exists. Gating
            // on is_dir() defeats the point. Emitters that need to make
            // real filesystem decisions (which non-public subdirs to
            // deny, which front controller a package ships) will find
            // the paths absent and skip those blocks.
            $fileroot = $bundleWeb . '/' . $id;
            $webroot = $overrides[$id] ?? ($defaultUrl . '/' . $id . '/');
            $apps[] = new AppEntry(
                id: $id,
                fileroot: $fileroot,
                webroot: $webroot,
                staticfs: $bundleWeb . '/static',
                staticuri: $defaultUrl . '/static/',
                jsfs: $bundleWeb . '/js/' . $id,
                jsuri: $defaultUrl . '/js/' . $id,
                themesfs: $bundleWeb . '/themes/' . $id,
                themesuri: $defaultUrl . '/themes/' . $id,
            );
        }
        return $this->finalize($apps, $errors);
    }

    /**
     * Shared conversion path for tiers 1 and 2. Accepts either a
     * slot-of-apps map or a bare app-map.
     */
    private function fromRegistryArray(array $slots): MapBuildResult
    {
        if ($slots === []) {
            return MapBuildResult::failure('Registry payload was empty.');
        }
        // Detect shape. If the first value is an array with a
        // `fileroot`, we're already looking at an app map (not a slot map).
        $firstValue = reset($slots);
        $isSlotMap = is_array($firstValue) && !isset($firstValue['fileroot']);
        $iterable = $isSlotMap ? $slots : ['default' => $slots];

        $seen = [];
        $apps = [];
        foreach ($iterable as $slotApps) {
            if (!is_array($slotApps)) {
                continue;
            }
            foreach ($slotApps as $id => $meta) {
                if (!is_array($meta) || !isset($meta['fileroot'], $meta['webroot'])) {
                    continue;
                }
                $key = $id . '@' . $meta['fileroot'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $apps[] = new AppEntry(
                    id: (string) $id,
                    fileroot: rtrim((string) $meta['fileroot'], '/'),
                    webroot: (string) $meta['webroot'],
                    staticfs: rtrim((string) ($meta['staticfs'] ?? ''), '/'),
                    staticuri: (string) ($meta['staticuri'] ?? ''),
                    jsfs: rtrim((string) ($meta['jsfs'] ?? ''), '/'),
                    jsuri: (string) ($meta['jsuri'] ?? ''),
                    themesfs: rtrim((string) ($meta['themesfs'] ?? ''), '/'),
                    themesuri: (string) ($meta['themesuri'] ?? ''),
                );
            }
        }
        return $this->finalize($apps, []);
    }

    /**
     * Runs collision detection and computes the default host / TLS
     * hint. Shared post-processing for all three tiers.
     *
     * @param list<AppEntry> $apps
     * @param list<string> $warnings Non-fatal warnings accumulated by callers.
     */
    private function finalize(array $apps, array $warnings): MapBuildResult
    {
        if ($apps === []) {
            return MapBuildResult::failure('No apps discovered in the input payload.');
        }

        // Collision: two different fileroots claiming the same webroot.
        $byWebroot = [];
        foreach ($apps as $app) {
            $byWebroot[$app->webroot][] = $app;
        }
        foreach ($byWebroot as $webroot => $collisionSet) {
            $filesroots = array_unique(array_map(fn (AppEntry $a) => $a->fileroot, $collisionSet));
            if (count($filesroots) > 1) {
                return MapBuildResult::failure(sprintf(
                    'Registry misconfiguration: webroot %s is claimed by different fileroots: %s',
                    $webroot,
                    implode(', ', $filesroots),
                ));
            }
        }

        // Default host / tls: derived from `horde` if present, first
        // absolute entry otherwise. Empty when no absolute webroot exists.
        $defaultHost = '';
        $defaultTls = false;
        foreach ($apps as $app) {
            if ($app->id === 'horde' && $app->isAbsolute()) {
                $defaultHost = $app->host();
                $defaultTls = $app->isTls();
                break;
            }
        }
        if ($defaultHost === '') {
            foreach ($apps as $app) {
                if ($app->isAbsolute()) {
                    $defaultHost = $app->host();
                    $defaultTls = $app->isTls();
                    break;
                }
            }
        }
        return MapBuildResult::success($apps, $defaultHost, $defaultTls, $warnings);
    }

    /**
     * Groups an app list by host. Apps with a relative webroot are
     * collected under the sentinel key '_default_'.
     *
     * @param list<AppEntry> $apps
     * @return array<string, list<AppEntry>>
     */
    public function groupByHost(array $apps): array
    {
        $out = [];
        foreach ($apps as $app) {
            $host = $app->isAbsolute() ? $app->host() : '_default_';
            $out[$host][] = $app;
        }
        return $out;
    }

    /**
     * Applies CLI-supplied overrides to an already-resolved map.
     *
     * Two knobs:
     *   - $appWebrootsSpec, same `id|url,id|url,...` format the tier-3
     *     synthesizer accepts. Every listed id has its webroot
     *     replaced by the given URL. Unlisted apps stay untouched.
     *   - $defaultUrl, an absolute base URL that fills in an anchor
     *     host for apps whose registry-supplied webroot is relative
     *     (e.g. '/imp/'). The path prefix is preserved; only the
     *     scheme+host portion is added.
     *
     * Runs AFTER any tier resolved a map, so live-registry + stdin
     * tiers can also benefit from the override surface. The tier-3
     * synthesizer already consumes both flags inline; those flags
     * are ignored here to avoid double-application.
     *
     * After overrides, re-runs collision detection and re-derives
     * the default-host / TLS hint. Fails the whole result if
     * overrides introduce a webroot collision.
     */
    public function applyOverrides(
        MapBuildResult $map,
        string $appWebrootsSpec = '',
        string $defaultUrl = '',
    ): MapBuildResult {
        if (!$map->ok) {
            return $map;
        }
        if ($appWebrootsSpec === '' && $defaultUrl === '') {
            return $map;
        }

        $warnings = $map->warnings;

        $overrides = [];
        if ($appWebrootsSpec !== '') {
            foreach (explode(',', $appWebrootsSpec) as $spec) {
                $spec = trim($spec);
                if ($spec === '') {
                    continue;
                }
                $parts = preg_split('/[|=]/', $spec, 2);
                if (!is_array($parts) || count($parts) !== 2) {
                    $warnings[] = sprintf('Ignoring malformed --app-webroots entry: %s', $spec);
                    continue;
                }
                $overrides[trim($parts[0])] = trim($parts[1]);
            }
        }

        $anchor = rtrim($defaultUrl, '/');

        $updated = [];
        foreach ($map->apps as $app) {
            $webroot = $app->webroot;
            if (isset($overrides[$app->id])) {
                $webroot = $overrides[$app->id];
            } elseif ($anchor !== '' && !$app->isAbsolute()) {
                // Relative webroot + operator-supplied anchor: promote
                // the entry to absolute. Preserve the path prefix.
                $webroot = $anchor . ($app->webroot !== '' ? '/' . ltrim($app->webroot, '/') : '/');
            }
            if ($webroot === $app->webroot) {
                $updated[] = $app;
                continue;
            }
            $updated[] = new AppEntry(
                id: $app->id,
                fileroot: $app->fileroot,
                webroot: $webroot,
                staticfs: $app->staticfs,
                staticuri: $app->staticuri,
                jsfs: $app->jsfs,
                jsuri: $app->jsuri,
                themesfs: $app->themesfs,
                themesuri: $app->themesuri,
            );
        }
        return $this->finalize($updated, $warnings);
    }
}
