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
 * Ordering matters in nginx location dispatch. Every app is wrapped
 * in a single `^~` prefix location so it wins over any regex location
 * at server scope. Inside it the forbid blocks (specific paths) come
 * first, the php handler next, and the front-controller fallback last.
 *
 * The php handler is scoped PER APP rather than at server scope: an
 * aliased subpath location needs SCRIPT_FILENAME derived from the
 * alias ($request_filename), whereas a root-anchored app served from
 * the server-level `root` uses $document_root$fastcgi_script_name. A
 * single server-scope handler cannot satisfy both.
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
                $this->renderSite($hostLabel, $apps, $phpHandlerSpec, $prefix),
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
        $rootAnchored = $app->isRootAnchored();
        $pathPrefix = rtrim($app->pathPrefix(), '/');
        $frontController = $this->render->frontControllerFor($app);
        // For root-anchored apps the location covers `/`; for subpath
        // apps the prefix path.
        $locationMatch = $rootAnchored ? '/' : $pathPrefix . '/';
        // SCRIPT_FILENAME has to reflect the on-disk path php-fpm should
        // execute. For a root-anchored app served from the server-level
        // `root`, $document_root$fastcgi_script_name is correct. For an
        // aliased subpath location that expression points at the server
        // root, NOT the app's fileroot, so php-fpm would fail to find
        // the script. $request_filename honours the alias and resolves
        // to <fileroot>/<script>.
        $scriptFilename = $rootAnchored
            ? '$document_root$fastcgi_script_name'
            : '$request_filename';

        $header = $this->render->prerequisitesHeader('nginx', [
            'Prerequisites:',
            '  - nginx 1.18+',
            '  - php-fpm listening at the fastcgi_pass emitted below',
            '  - Included from a `server { }` block in sites/',
            'Limitations:',
            '  - The nested location blocks must keep the order emitted:',
            '    forbid blocks first, php handler next, front-controller last.',
            '  - PATH_INFO is not split out; front controllers that route on',
            '    PATH_INFO need an operator-supplied fastcgi_split_path_info.',
            '',
            'App: ' . $app->id,
            'Fileroot: ' . $app->fileroot,
            'Webroot: ' . $app->webroot,
            $rootAnchored
                ? 'Anchoring: root (parent server block sets `root` to fileroot; no alias here).'
                : 'Anchoring: subpath (this snippet issues alias for ' . $pathPrefix . '/).',
            'Front controller: ' . $frontController,
        ]);

        // The whole app lives inside one `^~` prefix location. `^~`
        // makes this prefix win over any regex location at server scope,
        // and nesting the php handler here is what lets SCRIPT_FILENAME
        // pick up the alias for subpath apps.
        $body = $header;
        $body .= sprintf("location ^~ %s {\n", $locationMatch);
        // Root-anchored apps rely on the server-level `root` directive;
        // subpath apps get their own `alias` here.
        if (!$rootAnchored) {
            $body .= "    alias " . $app->fileroot . "/;\n";
        }
        $body .= "\n";
        // Forbid blocks first. For root-anchored apps the paths are
        // top-level (`/lib/`); for subpath apps they carry the prefix.
        foreach ($this->render->forbiddenDirs as $sub) {
            if (!is_dir($app->fileroot . '/' . $sub)) {
                continue;
            }
            $body .= sprintf(
                "    location ~ ^%s%s/ { return 403; }\n",
                $rootAnchored ? '/' : $pathPrefix . '/',
                $sub,
            );
        }
        // PHP handler, scoped to this app so SCRIPT_FILENAME is correct.
        $body .= "    location ~ \\.php$ {\n";
        $body .= "        include fastcgi_params;\n";
        $body .= "        fastcgi_param SCRIPT_FILENAME " . $scriptFilename . ";\n";
        $body .= "        fastcgi_param HTTP_AUTHORIZATION \$http_authorization;\n";
        $body .= "        " . $fastcgiDirective . "\n";
        $body .= "    }\n";
        // try_files fallback into the front controller, last. The target
        // path uses the URL prefix so the internal redirect re-enters
        // this location and hits the php handler above.
        $tryTarget = $rootAnchored
            ? '/' . $frontController
            : $pathPrefix . '/' . $frontController;
        $body .= "    try_files \$uri \$uri/ " . $tryTarget . "?\$args;\n";
        $body .= "}\n";
        return $body;
    }

    /**
     * @param list<AppEntry> $apps
     */
    private function renderSite(string $host, array $apps, string $phpHandlerSpec, string $prefix): string
    {
        [, $handlerLabel] = $this->render->phpHandler($phpHandlerSpec, 'nginx');
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
        } else {
            $body .= "    # No root-anchored app on this host. Set `root` manually\n";
            $body .= "    # or add an --app-webroots entry pointing an app at " . $host . "/.\n";
            $body .= "    # root /var/www/html;\n";
        }
        $body .= "\n";
        // Each app snippet carries its own php handler (scoped to the
        // app so SCRIPT_FILENAME honours the alias/root of that app).
        foreach ($apps as $app) {
            $body .= sprintf("    include %s/apps/%s.conf;\n", $includeBase, $app->id);
        }
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
