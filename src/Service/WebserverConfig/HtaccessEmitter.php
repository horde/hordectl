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
 * Emitter for the htaccess flavor. Pure: builds an in-memory list of
 * (path, content) entries. No filesystem writes.
 *
 * Consumed by the WebserverConfig\Htaccess command class, which
 * hands the plan to ConfigWriter.
 */
final class HtaccessEmitter
{
    public function __construct(private readonly RenderHelper $render)
    {
    }

    /**
     * @param list<AppEntry> $apps
     * @return list<EmitEntry>
     */
    public function emit(array $apps, string $outputDir): array
    {
        $entries = [];
        foreach ($apps as $app) {
            foreach ($this->emitOne($app, $outputDir) as $entry) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /**
     * @return list<EmitEntry>
     */
    private function emitOne(AppEntry $app, string $outputDir): array
    {
        $entries = [];
        $base = $outputDir . '/' . $app->id;

        // Root: front-controller rewrite with the auth-header restore.
        $entries[] = new EmitEntry(
            $base . '/.htaccess',
            $this->renderRoot($app),
        );

        // Forbid files for each non-public subdirectory that actually
        // exists under the fileroot.
        foreach ($this->render->forbiddenDirs as $sub) {
            if (!is_dir($app->fileroot . '/' . $sub)) {
                continue;
            }
            $entries[] = new EmitEntry(
                $base . '/' . $sub . '/.htaccess',
                $this->renderForbid(),
            );
        }

        // rpc/ dispatcher when the app carries one.
        if (is_dir($app->fileroot . '/rpc')) {
            $entries[] = new EmitEntry(
                $base . '/rpc/.htaccess',
                $this->renderDispatcher('index.php'),
            );
        }
        return $entries;
    }

    private function renderRoot(AppEntry $app): string
    {
        $frontController = $this->render->frontControllerFor($app);
        $header = $this->render->prerequisitesHeader('htaccess', [
            'Prerequisites:',
            '  - Apache 2.4+',
            '  - mod_rewrite loaded',
            '  - mod_authz_core loaded (auth_module on 2.2 is the fallback branch)',
            '  - AllowOverride FileInfo AuthConfig (or All) at the parent <Directory>',
            '  - Options +FollowSymLinks (or +SymLinksIfOwnerMatch) if the docroot uses symlinks',
            '  - PHP-FPM behind mod_proxy_fcgi (mod_php is not supported by this generator)',
            'Limitations:',
            '  - Static file. Regenerate with `hordectl webserver-config htaccess`',
            '    when the registry changes.',
            '  - Cannot follow registry entries whose webroot is on a different host.',
            '    Use the apache-vhost or nginx flavor for per-vhost setups.',
            '  - Silently ineffective when AllowOverride is None at the parent scope.',
            '',
            'App: ' . $app->id,
            'Fileroot: ' . $app->fileroot,
            'Webroot: ' . $app->webroot,
        ]);
        return $header
            . "<IfModule authz_core_module>\n"
            . "    Require all granted\n"
            . "</IfModule>\n"
            . "<IfModule !authz_core_module>\n"
            . "    Allow from all\n"
            . "</IfModule>\n\n"
            . "<IfModule mod_rewrite.c>\n"
            . "    RewriteEngine On\n"
            . "    RewriteRule .* - [env=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n"
            . "    RewriteRule .* - [env=REDIRECT_HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n"
            . "    RewriteCond   %{REQUEST_FILENAME}  !-d\n"
            . "    RewriteCond   %{REQUEST_FILENAME}  !-f\n"
            . "    RewriteRule ^(.*)$ " . $frontController . " [QSA,L]\n"
            . "</IfModule>\n";
    }

    private function renderForbid(): string
    {
        $header = $this->render->prerequisitesHeader('htaccess', [
            'Prerequisites: Apache 2.4+ with mod_authz_core, or 2.2 with mod_auth (fallback).',
            'Limitations: Silently ineffective when AllowOverride is None.',
        ]);
        return $header
            . "<IfModule authz_core_module>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !authz_core_module>\n"
            . "    Deny from all\n"
            . "</IfModule>\n";
    }

    private function renderDispatcher(string $dispatcher): string
    {
        $header = $this->render->prerequisitesHeader('htaccess', [
            'Prerequisites: Apache 2.4+ with mod_rewrite.',
            'Limitations: Silently ineffective when AllowOverride is None.',
        ]);
        return $header
            . "<IfModule mod_rewrite.c>\n"
            . "    RewriteEngine On\n"
            . "    RewriteCond   %{REQUEST_FILENAME}  !-d\n"
            . "    RewriteCond   %{REQUEST_FILENAME}  !-f\n"
            . "    RewriteRule   ^(.*)$ " . $dispatcher . "/$1 [QSA,L]\n"
            . "</IfModule>\n";
    }
}
