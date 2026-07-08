<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Test\Service\WebserverConfig;

use Horde\Hordectl\Service\WebserverConfig\AppEntry;
use Horde\Hordectl\Service\WebserverConfig\EmitEntry;
use Horde\Hordectl\Service\WebserverConfig\MapBuildResult;
use Horde\Hordectl\Service\WebserverConfig\NginxEmitter;
use Horde\Hordectl\Service\WebserverConfig\RenderHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the nginx flavor emitter, with emphasis on the
 * SCRIPT_FILENAME / alias correctness that a plain server-scope php
 * handler could not provide.
 */
#[CoversClass(NginxEmitter::class)]
class NginxEmitterTest extends TestCase
{
    private string $tmpRoot = '';

    private string $phpHandler = 'unix-socket:/run/php/php8.4-fpm.sock';

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/hordectl-nginx-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpRoot !== '' && is_dir($this->tmpRoot)) {
            $this->rrmdir($this->tmpRoot);
        }
    }

    public function testSubpathAppUsesRequestFilenameNotDocumentRoot(): void
    {
        $fileroot = $this->makeFileroot('imp', ['lib']);
        $map = MapBuildResult::success(
            [new AppEntry(id: 'imp', fileroot: $fileroot, webroot: '/imp/')],
            '',
            false,
        );

        $conf = $this->appConf($map, 'imp');

        // The whole app is wrapped in a `^~` prefix location.
        $this->assertStringContainsString('location ^~ /imp/ {', $conf);
        // Subpath apps get their own alias.
        $this->assertStringContainsString('alias ' . $fileroot . '/;', $conf);
        // The php handler is nested and derives SCRIPT_FILENAME from the
        // alias. This is the core regression guard.
        $this->assertStringContainsString(
            'fastcgi_param SCRIPT_FILENAME $request_filename;',
            $conf,
        );
        $this->assertStringNotContainsString(
            '$document_root$fastcgi_script_name',
            $conf,
        );
        // fastcgi_pass is emitted per app.
        $this->assertStringContainsString(
            'fastcgi_pass unix:/run/php/php8.4-fpm.sock;',
            $conf,
        );
        // Front-controller fallback keeps the URL prefix so the internal
        // redirect re-enters this location.
        $this->assertStringContainsString(
            'try_files $uri $uri/ /imp/rampage.php?$args;',
            $conf,
        );
        // Forbid block for the existing lib/ directory, prefixed and nested.
        $this->assertStringContainsString(
            'location ~ ^/imp/lib/ { return 403; }',
            $conf,
        );
    }

    public function testRootAnchoredAppUsesDocumentRoot(): void
    {
        $fileroot = $this->makeFileroot('horde', ['lib']);
        $map = MapBuildResult::success(
            [new AppEntry(id: 'horde', fileroot: $fileroot, webroot: '/')],
            '',
            false,
        );

        $conf = $this->appConf($map, 'horde');

        $this->assertStringContainsString('location ^~ / {', $conf);
        // Root-anchored apps rely on the server-level `root`; no alias
        // directive is emitted (the header comment may mention the word).
        $this->assertStringNotContainsString("\n    alias ", $conf);
        $this->assertStringContainsString(
            'fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;',
            $conf,
        );
        $this->assertStringContainsString(
            'try_files $uri $uri/ /rampage.php?$args;',
            $conf,
        );
        $this->assertStringContainsString(
            'location ~ ^/lib/ { return 403; }',
            $conf,
        );
    }

    public function testSiteBlockNoLongerCarriesServerScopePhpHandler(): void
    {
        $fileroot = $this->makeFileroot('horde', []);
        $map = MapBuildResult::success(
            [new AppEntry(id: 'horde', fileroot: $fileroot, webroot: '/')],
            'horde.example.org',
            false,
        );

        $site = $this->siteConf($map);

        // php-fpm handling now lives in the per-app snippet only. The
        // site block just includes the app confs.
        $this->assertStringNotContainsString('fastcgi_pass', $site);
        $this->assertStringContainsString(
            'include /etc/nginx/horde-includes/apps/horde.conf;',
            $site,
        );
    }

    public function testAppSnippetOmitsForbidBlockForAbsentDir(): void
    {
        // No subdirectories created: forbid blocks must not be emitted
        // for directories that do not exist on disk.
        $fileroot = $this->makeFileroot('nag', []);
        $map = MapBuildResult::success(
            [new AppEntry(id: 'nag', fileroot: $fileroot, webroot: '/nag/')],
            '',
            false,
        );

        $conf = $this->appConf($map, 'nag');

        $this->assertStringNotContainsString('return 403', $conf);
    }

    private function appConf(MapBuildResult $map, string $appId): string
    {
        $emitter = new NginxEmitter(new RenderHelper());
        $entries = $emitter->emit($map, $this->tmpRoot . '/out', $this->phpHandler, '/etc/nginx');
        return $this->contentFor($entries, '/horde-includes/apps/' . $appId . '.conf');
    }

    private function siteConf(MapBuildResult $map): string
    {
        $emitter = new NginxEmitter(new RenderHelper());
        $entries = $emitter->emit($map, $this->tmpRoot . '/out', $this->phpHandler, '/etc/nginx');
        return $this->contentFor($entries, '/sites/');
    }

    /**
     * @param list<EmitEntry> $entries
     */
    private function contentFor(array $entries, string $pathSuffix): string
    {
        foreach ($entries as $entry) {
            if (str_contains($entry->path, $pathSuffix)) {
                return $entry->content;
            }
        }
        $this->fail('No emitted entry matched ' . $pathSuffix);
    }

    /**
     * @param list<string> $subdirs
     */
    private function makeFileroot(string $id, array $subdirs): string
    {
        $fileroot = $this->tmpRoot . '/web/' . $id;
        mkdir($fileroot, 0o755, true);
        file_put_contents($fileroot . '/rampage.php', "<?php\n");
        foreach ($subdirs as $sub) {
            mkdir($fileroot . '/' . $sub, 0o755, true);
        }
        return $fileroot;
    }

    private function rrmdir(string $dir): void
    {
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
