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
use Horde\Hordectl\Service\WebserverConfig\AppMapBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the tier-resolution and override logic in AppMapBuilder.
 */
#[CoversClass(AppMapBuilder::class)]
class AppMapBuilderTest extends TestCase
{
    public function testFromDefaultsProducesRelativeWebroots(): void
    {
        $builder = new AppMapBuilder();
        $map = $builder->fromDefaults('', '/nonexistent-bundle', '');

        $this->assertTrue($map->ok);
        $horde = $this->app($map->apps, 'horde');
        $this->assertNotNull($horde);
        $this->assertSame('/horde/', $horde->webroot);
        $this->assertFalse($horde->isAbsolute());
    }

    public function testFromDefaultsAppliesInlineOverrides(): void
    {
        $builder = new AppMapBuilder();
        $map = $builder->fromDefaults(
            'http://localhost',
            '/nonexistent-bundle',
            'imp|https://webmail.example.org',
        );

        $this->assertTrue($map->ok);
        $imp = $this->app($map->apps, 'imp');
        $this->assertNotNull($imp);
        $this->assertSame('https://webmail.example.org', $imp->webroot);
        // A non-overridden app inherits --default-url.
        $turba = $this->app($map->apps, 'turba');
        $this->assertNotNull($turba);
        $this->assertSame('http://localhost/turba/', $turba->webroot);
    }

    public function testApplyOverridesPromotesRelativeWebrootToAbsolute(): void
    {
        $builder = new AppMapBuilder();
        $map = $builder->fromDefaults('', '/nonexistent-bundle', '');

        $promoted = $builder->applyOverrides($map, '', 'http://localhost');

        $this->assertTrue($promoted->ok);
        $imp = $this->app($promoted->apps, 'imp');
        $this->assertNotNull($imp);
        $this->assertSame('http://localhost/imp/', $imp->webroot);
    }

    public function testApplyOverridesReplacesPerAppWebroot(): void
    {
        $builder = new AppMapBuilder();
        $map = $builder->fromDefaults('', '/nonexistent-bundle', '');

        $overridden = $builder->applyOverrides($map, 'imp|https://webmail.example.org', '');

        $this->assertTrue($overridden->ok);
        $imp = $this->app($overridden->apps, 'imp');
        $this->assertNotNull($imp);
        $this->assertSame('https://webmail.example.org', $imp->webroot);
    }

    public function testApplyOverridesDetectsWebrootCollision(): void
    {
        $builder = new AppMapBuilder();
        $map = $builder->fromDefaults('', '/nonexistent-bundle', '');

        // Point two different apps (distinct fileroots) at one webroot.
        $collided = $builder->applyOverrides(
            $map,
            'imp|https://x.example.org/dup,turba|https://x.example.org/dup',
            '',
        );

        $this->assertFalse($collided->ok);
        $this->assertStringContainsString('different fileroots', $collided->error);
    }

    public function testApplyOverridesRecordsMalformedEntryAsWarning(): void
    {
        $builder = new AppMapBuilder();
        $map = $builder->fromDefaults('', '/nonexistent-bundle', '');

        $result = $builder->applyOverrides($map, 'this-has-no-separator', 'http://localhost');

        $this->assertTrue($result->ok);
        $this->assertNotEmpty($result->warnings);
        $this->assertStringContainsString('malformed', $result->warnings[0]);
    }

    public function testFromStdinYamlEmptyFails(): void
    {
        $builder = new AppMapBuilder();
        $map = $builder->fromStdinYaml('');

        $this->assertFalse($map->ok);
    }

    public function testFromStdinYamlBareAppMap(): void
    {
        $builder = new AppMapBuilder();
        $yaml = <<<YAML
horde:
    fileroot: /var/www/horde/web/horde
    webroot: /horde
imp:
    fileroot: /var/www/horde/web/imp
    webroot: /imp
YAML;
        $map = $builder->fromStdinYaml($yaml);

        $this->assertTrue($map->ok);
        $this->assertCount(2, $map->apps);
        $horde = $this->app($map->apps, 'horde');
        $this->assertNotNull($horde);
        $this->assertSame('/horde', $horde->webroot);
        $this->assertSame('/var/www/horde/web/horde', $horde->fileroot);
    }

    public function testFromStdinYamlEnvelopeShape(): void
    {
        $builder = new AppMapBuilder();
        // The wrapped shape `hordectl query registry` emits.
        $yaml = <<<YAML
apps:
    builtin:
        resources:
            registry:
                items:
                    default:
                        horde:
                            fileroot: /var/www/horde/web/horde
                            webroot: /horde
YAML;
        $map = $builder->fromStdinYaml($yaml);

        $this->assertTrue($map->ok);
        $horde = $this->app($map->apps, 'horde');
        $this->assertNotNull($horde);
        $this->assertSame('/horde', $horde->webroot);
    }

    public function testDefaultHostPrefersHordeApp(): void
    {
        $builder = new AppMapBuilder();
        $yaml = <<<YAML
imp:
    fileroot: /var/www/horde/web/imp
    webroot: https://webmail.example.org/
horde:
    fileroot: /var/www/horde/web/horde
    webroot: https://horde.example.org/
YAML;
        $map = $builder->fromStdinYaml($yaml);

        $this->assertTrue($map->ok);
        $this->assertSame('horde.example.org', $map->defaultHost);
    }

    /**
     * @param list<AppEntry> $apps
     */
    private function app(array $apps, string $id): ?AppEntry
    {
        foreach ($apps as $app) {
            if ($app->id === $id) {
                return $app;
            }
        }
        return null;
    }
}
