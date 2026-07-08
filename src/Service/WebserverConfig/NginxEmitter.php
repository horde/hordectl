<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Service\WebserverConfig;

/**
 * Emitter for the nginx flavor. Pure: returns a list of
 * (path, content, sketch) triples.
 *
 * Output layout under $outputDir:
 *   apps/<app>.conf      Per-app location blocks
 *   sites/<host>.conf    Per-host server blocks
 *   tls/<host>.conf      Operator-owned TLS knobs (sketch mode)
 *
 * Ordering matters in nginx location dispatch. The emitter puts
 * forbid blocks (specific paths) before the alias-based front-controller
 * fallback, and the fastcgi handler goes at server scope so it applies
 * across every included location block.
 */
final class NginxEmitter
{
    public function __construct(private readonly RenderHelper $render)
    {
    }

    /**
     * @return list<EmitEntry>
     */
    public function emit(MapBuildResult $map, string $outputDir, string $phpHandlerSpec, string $prefix): array
    {
        $entries = [];
        foreach ($map->apps as $app) {
            $entries[] = new EmitEntry(
                $outputDir . '/horde-includes/apps/' . $app->id . '.conf',
                $this->renderAppSnippet($app, $phpHandlerSpec),
            );
        }
        $byHost = $this->groupByHost($map);
        foreach ($byHost as $host => $apps) {
            $hostLabel = $host === '_default_' ? ($map->defaultHost ?: 'default') : $host;
            $entries[] = new EmitEntry(
                $outputDir . '/sites/' . $hostLabel . '.conf',
                $this->renderSite($hostLabel, $apps, $phpHandlerSpec, $prefix, $map->bundleWebRoot),
            );
            if ($this->render->anyTls($apps)) {
                $entries[] = new EmitEntry(
                    $outputDir . '/horde-includes/tls/' . $hostLabel . '.conf',
                    $this->render->tlsSketch($hostLabel, 'nginx'),
                    sketch: true,
                );
            }
        }
        return $entries;
    }

    /**
     * @return array<string, list<AppEntry>>
     */
    private function groupByHost(MapBuildResult $map): array
    {
        $out = [];
        foreach ($map->apps as $app) {
            $key = $app->isAbsolute() ? $app->host() : '_default_';
            $out[$key][] = $app;
        }
        return $out;
    }

    private function renderAppSnippet(AppEntry $app, string $phpHandlerSpec): string
    {
        [$fastcgiDirective] = $this->render->phpHandler($phpHandlerSpec, 'nginx');
        $frontController = $this->render->frontControllerFor($app);
        $pathPrefix = rtrim($app->pathPrefix(), '/');
        // For root-anchored apps the location covers `/`; for subpath
        // apps the prefix path.
        $locationMatch = $app->isRootAnchored() ? '/' : $pathPrefix . '/';
        // Named location per app. `@` prevents nginx from treating it
        // as a URI (no location rematch, no alias inheritance drama).
        $rampageLoc = '@' . $this->namedLocationLabel($app) . '_rampage';
        // The front controller lives at a known absolute path so we
        // hardcode SCRIPT_FILENAME. nginx's alias + try_files
        // URI-fallback interaction mangles $fastcgi_script_name on the
        // internal-redirect subrequest, and $request_filename inside
        // the redirected location doesn't help either (measured on
        // Ubuntu 24.04 with nginx 1.24 against the horde/bundle web
        // layout). The named location bypasses both by carrying the
        // absolute filename directly.
        $frontControllerFile = $app->fileroot . '/' . $frontController;

        $header = $this->render->prerequisitesHeader('nginx', [
            'Prerequisites:',
            '  - nginx 1.18+',
            '  - php-fpm reachable via the parent server block',
            '  - Included from a `server { }` block in sites/',
            'Limitations:',
            '  - Bare `/<app>/` (trailing slash) relies on the parent site',
            '    file emitting a `rewrite ^(/[^/]+)/$ $1/index.php last;`',
            '    so nginx behaves like Apache\'s `DirectoryIndex index.php`.',
            '',
            'App: ' . $app->id,
            'Fileroot: ' . $app->fileroot,
            'Webroot: ' . $app->webroot,
            $app->isRootAnchored()
                ? 'Anchoring: root (parent server block sets `root` to fileroot; no alias here).'
                : 'Anchoring: subpath (this snippet issues alias for ' . $pathPrefix . '/).',
            'Front controller: ' . $frontController . ' (invoked via ' . $rampageLoc . ')',
        ]);

        $body = $header;
        // Forbid blocks. For root-anchored apps the paths are top-level
        // (`/lib/`); for subpath apps they carry the prefix.
        foreach ($this->render->forbiddenDirs as $sub) {
            if (!is_dir($app->fileroot . '/' . $sub)) {
                continue;
            }
            $body .= sprintf(
                "location ~ ^%s%s/ { return 403; }\n",
                $app->isRootAnchored() ? '/' : $pathPrefix . '/',
                $sub,
            );
        }
        $body .= "\n";
        $body .= sprintf("location %s {\n", $locationMatch);
        // Root-anchored apps rely on the server-level `root` directive;
        // subpath apps get their own `alias` here.
        if (!$app->isRootAnchored()) {
            $body .= "    alias " . $app->fileroot . "/;\n";
        }
        // try_files tests $uri as a filesystem path. Real files
        // (login.php, index.php by name, static assets) match here
        // and get served directly — the server-scope `.php$` regex
        // picks up any PHP among them. Everything else falls through
        // to the named location which invokes the front controller.
        $body .= "    try_files \$uri " . $rampageLoc . ";\n";
        $body .= "}\n\n";

        // Named location: hardcoded SCRIPT_FILENAME, PATH_INFO carries
        // the requested URI so rampage's routing sees the same thing
        // Apache would have handed it.
        $body .= sprintf("location %s {\n", $rampageLoc);
        $body .= "    include fastcgi_params;\n";
        $body .= "    fastcgi_param SCRIPT_FILENAME " . $frontControllerFile . ";\n";
        $body .= "    fastcgi_param PATH_INFO       \$uri;\n";
        $body .= "    fastcgi_param HTTP_AUTHORIZATION \$http_authorization;\n";
        $body .= "    " . $fastcgiDirective . "\n";
        $body .= "}\n";
        return $body;
    }

    /**
     * nginx named-location labels must be lowercase word-chars and
     * underscores. App ids like `horde-webmail` are legal in
     * `.horde.yml` but not in nginx. Sanitize.
     */
    private function namedLocationLabel(AppEntry $app): string
    {
        return preg_replace('/[^a-z0-9_]+/', '_', strtolower($app->id)) ?? $app->id;
    }

    /**
     * @param list<AppEntry> $apps
     */
    private function renderSite(string $host, array $apps, string $phpHandlerSpec, string $prefix, string $bundleWebRoot = ''): string
    {
        [$fastcgiDirective, $handlerLabel] = $this->render->phpHandler($phpHandlerSpec, 'nginx');
        $anyTls = $this->render->anyTls($apps);

        // Identify the root-anchored app, if any. Only one is legal.
        $rootAnchored = [];
        foreach ($apps as $app) {
            if ($app->isRootAnchored()) {
                $rootAnchored[] = $app;
            }
        }
        $rootApp = $rootAnchored[0] ?? null;
        $rootCollision = count($rootAnchored) > 1;

        $includeBase = rtrim($prefix, '/') . '/horde-includes';

        $header = $this->render->prerequisitesHeader('nginx', [
            'Prerequisites:',
            '  - nginx 1.18+',
            '  - php-fpm listening at: ' . $handlerLabel,
            '  - horde-includes/ tree copied under nginx prefix: ' . $prefix,
            $anyTls ? '  - Fill in ' . $includeBase . '/tls/' . $host . '.conf before enabling TLS' : '',
            'Limitations:',
            '  - Cannot follow certbot / operator-managed TLS knobs.',
            '',
            'Host: ' . $host,
            'Apps: ' . implode(', ', array_map(fn (AppEntry $a) => $a->id, $apps)),
            $rootApp !== null
                ? 'Root app (owns server `root`): ' . $rootApp->id
                : 'No root-anchored app on this host. The generator omits the server-level `root`.',
            '',
            'Drop THIS file into your distro\'s sweep directory:',
            '  Debian/Ubuntu:  /etc/nginx/sites-available/  (symlink into sites-enabled/)',
            '  SUSE:           /etc/nginx/vhosts.d/         (drop *.conf into dir)',
            '  RedHat/Fedora:  /etc/nginx/conf.d/           (drop *.conf into dir)',
            'Drop the horde-includes/ tree next to it under:',
            '  ' . $prefix . '/horde-includes/',
            '',
            'Common php-fpm socket paths by distro:',
            '  Ubuntu/Debian:  /run/php/php<version>-fpm.sock  (current default)',
            '  RHEL/Fedora:    /run/php-fpm/www.sock',
            '  SUSE:           /var/run/php-fpm.sock',
            'Override with --php-handler=unix-socket:<path> or --php-handler=tcp:<host>:<port>.',
            '',
            'Customization:',
            '  Any *.conf file in ' . $includeBase . '/local.d/' . $host . '/ is included',
            '  automatically. hordectl never touches that directory so your',
            '  knobs survive regeneration. The directory may not exist yet;',
            '  nginx tolerates that.',
        ]);

        $port = $anyTls ? '443 ssl' : '80';
        $body = $header;
        if ($rootCollision) {
            $collidingIds = implode(', ', array_map(fn (AppEntry $a) => $a->id, $rootAnchored));
            $body .= "# ERROR: multiple apps on host " . $host . " are root-anchored: " . $collidingIds . "\n";
            $body .= "# nginx can only bind one `root` per server block.\n";
            $body .= "# Give all but one of these apps a path prefix in --app-webroots.\n\n";
        }
        $body .= "server {\n";
        $body .= "    listen " . $port . ";\n";
        $body .= "    server_name " . $host . ";\n";
        if ($rootApp !== null) {
            $body .= '    root ' . $rootApp->fileroot . ";\n";
        } elseif ($bundleWebRoot !== '') {
            // Tier-3 knows the bundle web root; that's the natural
            // vhost root when no app owns `/`. Everything under
            // `<bundle>/web/` (horde/, imp/, static/, js/, themes/)
            // resolves under /<app>/, /static/*, /js/* etc.
            $body .= '    root ' . $bundleWebRoot . ";\n";
        } else {
            $body .= "    # No root-anchored app on this host and no bundle web\n";
            $body .= "    # root known (live-registry / stdin tiers). Set `root`\n";
            $body .= "    # manually or regenerate with --root-bundle-path.\n";
            $body .= "    # root /var/www/html;\n";
        }
        $body .= "\n";
        // DirectoryIndex-equivalent: bare `/<app>/` (any single URL
        // segment with a trailing slash) rewrites to `/<app>/index.php`.
        // The `.php$` regex below then executes it. Matches Apache's
        // `DirectoryIndex index.php` without special-casing app ids.
        // Deeper subdir requests (e.g. `/<app>/services/`) don't match
        // and pass through to the per-app location.
        $body .= "    rewrite ^(/[^/]+)/$ \$1/index.php last;\n\n";
        foreach ($apps as $app) {
            $body .= sprintf("    include %s/apps/%s.conf;\n", $includeBase, $app->id);
        }
        $body .= "\n";
        $body .= "    location ~ \\.php$ {\n";
        $body .= "        include fastcgi_params;\n";
        $body .= "        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n";
        $body .= "        fastcgi_param HTTP_AUTHORIZATION \$http_authorization;\n";
        $body .= "        " . $fastcgiDirective . "\n";
        $body .= "    }\n";
        if ($anyTls) {
            $body .= sprintf("\n    include %s/tls/%s.conf;\n", $includeBase, $host);
        }
        // Operator-owned customization hook. Glob include tolerates
        // the directory being empty or absent.
        $body .= sprintf("\n    include %s/local.d/%s/*.conf;\n", $includeBase, $host);
        $body .= "}\n";
        return $body;
    }
}
