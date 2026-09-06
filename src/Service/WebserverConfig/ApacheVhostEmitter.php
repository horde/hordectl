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
 * Emitter for the apache-vhost flavor. Pure: returns a list of
 * (path, content, sketch) triples.
 *
 * Output layout under $outputDir:
 *   apps/<app>.conf      Per-app <Directory> snippets
 *   sites/<host>.conf    Per-host <VirtualHost> wrappers
 *   tls/<host>.conf      Operator-owned TLS knobs (sketch mode)
 */
final class ApacheVhostEmitter
{
    public function __construct(private readonly RenderHelper $render)
    {
    }

    /**
     * @return list<EmitEntry>
     */
    public function emit(MapBuildResult $map, string $outputDir, string $phpHandlerSpec, string $serverroot): array
    {
        $entries = [];
        // Per-app snippets live under horde-includes/apps/, NOT under
        // sites/. That keeps them out of the distro's *.conf sweep of
        // sites-available/vhosts.d/conf.d.
        foreach ($map->apps as $app) {
            $entries[] = new EmitEntry(
                $outputDir . '/horde-includes/apps/' . $app->id . '.conf',
                $this->renderAppSnippet($app),
            );
        }
        // Per-host site files. These ARE meant for the sweep dir.
        $byHost = $this->groupByHost($map);
        foreach ($byHost as $host => $apps) {
            $hostLabel = $host === '_default_' ? ($map->defaultHost ?: 'default') : $host;
            $entries[] = new EmitEntry(
                $outputDir . '/sites/' . $hostLabel . '.conf',
                $this->renderSite($hostLabel, $apps, $phpHandlerSpec, $serverroot, $map->bundleWebRoot),
            );
            if ($this->render->anyTls($apps)) {
                $entries[] = new EmitEntry(
                    $outputDir . '/horde-includes/tls/' . $hostLabel . '.conf',
                    $this->render->tlsSketch($hostLabel, 'apache'),
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

    private function renderAppSnippet(AppEntry $app): string
    {
        $header = $this->render->prerequisitesHeader('apache-vhost', [
            'Prerequisites:',
            '  - Apache 2.4+',
            '  - mod_rewrite, mod_authz_core, mod_proxy_fcgi loaded',
            '  - Included from a <VirtualHost> in sites/',
            'Limitations:',
            '  - Sets AllowOverride None on every <Directory> block.',
            '    Any .htaccess files under the tree WILL BE IGNORED. That is the point.',
            '',
            'App: ' . $app->id,
            'Fileroot: ' . $app->fileroot,
            'Webroot: ' . $app->webroot,
            $app->isRootAnchored()
                ? 'Anchoring: root (parent vhost sets DocumentRoot to fileroot; no Alias here).'
                : 'Anchoring: subpath (this snippet issues Alias ' . rtrim($app->pathPrefix(), '/') . ' -> fileroot).',
            '',
            'Target directory for the parent sites/*.conf on major distros:',
            '  Debian/Ubuntu:  /etc/apache2/sites-available/  (enable via `a2ensite`)',
            '  SUSE:           /etc/apache2/vhosts.d/         (drop *.conf into dir)',
            '  RedHat/Fedora:  /etc/httpd/conf.d/             (drop *.conf into dir)',
        ]);
        $frontController = $this->render->frontControllerFor($app);
        $pathPrefix = rtrim($app->pathPrefix(), '/');

        $body = $header;
        // Root-anchored apps do NOT get an Alias line here; the parent
        // vhost sets DocumentRoot to fileroot. Subpath apps do.
        if (!$app->isRootAnchored()) {
            $body .= sprintf("Alias %s %s/\n\n", $pathPrefix, $app->fileroot);
        }
        $body .= sprintf("<Directory %s>\n", $app->fileroot);
        $body .= "    AllowOverride None\n";
        $body .= "    Options +FollowSymLinks -Indexes\n";
        // Serve a directory's index.php natively (e.g.
        // /<app>/services/portal/). The front-controller RewriteRule
        // below is guarded by `!-d`, so a real directory is never sent
        // to rampage.php — it falls to DirectoryIndex instead. Set it
        // explicitly rather than depending on an unstated server-global
        // DirectoryIndex, so the config is self-contained.
        $body .= "    DirectoryIndex index.php\n";
        $body .= "    Require all granted\n\n";
        $body .= "    RewriteEngine On\n";
        // RewriteBase anchors the rewrite substitution so `rampage.php`
        // resolves under this app's URL prefix, not the vhost root.
        // For root-anchored apps this is `/`; for subpath apps this is
        // `/imp/` or similar.
        $body .= '    RewriteBase ' . ($app->isRootAnchored() ? '/' : $pathPrefix . '/') . "\n";
        $body .= "    RewriteRule .* - [env=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n";
        $body .= "    RewriteRule .* - [env=REDIRECT_HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n";
        $body .= "    RewriteCond   %{REQUEST_FILENAME}  !-d\n";
        $body .= "    RewriteCond   %{REQUEST_FILENAME}  !-f\n";
        $body .= "    RewriteRule ^(.*)$ " . $frontController . " [QSA,L]\n";
        $body .= "</Directory>\n\n";

        foreach ($this->render->forbiddenDirs as $sub) {
            $subPath = $app->fileroot . '/' . $sub;
            if (!is_dir($subPath)) {
                continue;
            }
            $body .= sprintf("<Directory %s>\n", $subPath);
            $body .= "    AllowOverride None\n";
            $body .= "    Require all denied\n";
            $body .= "</Directory>\n\n";
        }
        return $body;
    }

    /**
     * @param list<AppEntry> $apps
     */
    private function renderSite(string $host, array $apps, string $phpHandlerSpec, string $serverroot, string $bundleWebRoot = ''): string
    {
        [$handlerDirective, $handlerLabel] = $this->render->phpHandler($phpHandlerSpec, 'apache');
        $anyTls = $this->render->anyTls($apps);

        // Identify the root-anchored app, if any. Only one is legal;
        // two would fight over DocumentRoot. The whole tree collapses
        // into a diagnostic if that happens.
        $rootAnchored = [];
        foreach ($apps as $app) {
            if ($app->isRootAnchored()) {
                $rootAnchored[] = $app;
            }
        }
        $rootApp = $rootAnchored[0] ?? null;
        $rootCollision = count($rootAnchored) > 1;

        $includeBase = rtrim($serverroot, '/') . '/horde-includes';

        $header = $this->render->prerequisitesHeader('apache-vhost', [
            'Prerequisites:',
            '  - Apache 2.4+',
            '  - mod_rewrite, mod_authz_core, mod_proxy_fcgi loaded',
            '  - php-fpm listening at: ' . $handlerLabel,
            '  - horde-includes/ tree copied under ServerRoot: ' . $serverroot,
            $anyTls ? '  - Fill in ' . $includeBase . '/tls/' . $host . '.conf before enabling TLS' : '',
            'Limitations:',
            '  - Cannot follow certbot / operator-managed TLS knobs. That lives',
            '    in the tls/ sketch and is written only once.',
            '',
            'Host: ' . $host,
            'Apps: ' . implode(', ', array_map(fn (AppEntry $a) => $a->id, $apps)),
            $rootApp !== null
                ? 'Root app (owns DocumentRoot): ' . $rootApp->id
                : 'No root-anchored app on this host. DocumentRoot points at the bundle web root.',
            '',
            'Drop THIS file into your distro\'s sweep directory:',
            '  Debian/Ubuntu:  /etc/apache2/sites-available/  (enable via `a2ensite`)',
            '  SUSE:           /etc/apache2/vhosts.d/         (drop *.conf into dir)',
            '  RedHat/Fedora:  /etc/httpd/conf.d/             (drop *.conf into dir)',
            'Drop the horde-includes/ tree next to it under:',
            '  ' . $serverroot . '/horde-includes/',
            '',
            'Common php-fpm socket paths by distro:',
            '  Ubuntu/Debian:  /run/php/php<version>-fpm.sock  (current default)',
            '  RHEL/Fedora:    /run/php-fpm/www.sock',
            '  SUSE:           /var/run/php-fpm.sock',
            'Override with --php-handler=unix-socket:<path> or --php-handler=tcp:<host>:<port>.',
            '',
            'Customization:',
            '  Anything you drop into ' . $includeBase . '/local/' . $host . '.conf',
            '  is included automatically. hordectl never touches that file',
            '  so your knobs survive regeneration.',
        ]);

        $port = $anyTls ? '443' : '80';
        $body = $header;
        if ($rootCollision) {
            $collidingIds = implode(', ', array_map(fn (AppEntry $a) => $a->id, $rootAnchored));
            $body .= "# ERROR: multiple apps on host " . $host . " are root-anchored: " . $collidingIds . "\n";
            $body .= "# Apache can only bind DocumentRoot to one directory per vhost.\n";
            $body .= "# Give all but one of these apps a path prefix in --app-webroots.\n\n";
        }
        $body .= sprintf("<VirtualHost *:%s>\n", $port);
        $body .= '    ServerName ' . $host . "\n";
        if ($rootApp !== null) {
            $body .= '    DocumentRoot ' . $rootApp->fileroot . "\n";
        } elseif ($bundleWebRoot !== '') {
            // Tier-3 knows the bundle web root; that's the natural
            // vhost docroot when no app owns `/`. Everything under
            // `<bundle>/web/` (horde/, imp/, static/, js/, themes/)
            // resolves under /<app>/, /static/*, /js/* etc.
            $body .= '    DocumentRoot ' . $bundleWebRoot . "\n";
        } else {
            $body .= "    # No root-anchored app on this host and no bundle web\n";
            $body .= "    # root known (live-registry / stdin tiers). Set DocumentRoot\n";
            $body .= "    # manually or regenerate with --root-bundle-path.\n";
            $body .= "    # DocumentRoot /var/www/html\n";
        }
        $body .= "\n";
        $body .= "    SetEnvIfNoCase ^Authorization$ (.+) HTTP_AUTHORIZATION=$1\n\n";
        $body .= "    <FilesMatch \\.php$>\n";
        $body .= '        ' . $handlerDirective . "\n";
        $body .= "    </FilesMatch>\n\n";
        foreach ($apps as $app) {
            $body .= sprintf("    Include %s/apps/%s.conf\n", $includeBase, $app->id);
        }
        if ($anyTls) {
            $body .= sprintf("\n    Include %s/tls/%s.conf\n", $includeBase, $host);
        }
        // Operator-owned customization hook, always last so it can
        // override anything the generator emitted.
        $body .= sprintf("\n    IncludeOptional %s/local/%s.conf\n", $includeBase, $host);
        $body .= "</VirtualHost>\n";
        return $body;
    }
}
