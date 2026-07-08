<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Test\Command\WebserverConfig;

use Horde\Hordectl\Command\WebserverConfig\WebserverConfigOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the static tier-classification and flag-extraction helpers
 * that feed the README provenance block.
 */
#[CoversClass(WebserverConfigOptions::class)]
class WebserverConfigOptionsTest extends TestCase
{
    public function testClassifyTierDefaultsWhenBundlePathPresent(): void
    {
        $opts = (object) ['root_bundle_path' => '/var/www/horde'];
        $this->assertSame('defaults', WebserverConfigOptions::classifyTier($opts));
    }

    public function testClassifyTierStdinWhenRegistryInDash(): void
    {
        $opts = (object) ['registry_in' => '-'];
        $this->assertSame('stdin-yaml', WebserverConfigOptions::classifyTier($opts));
    }

    public function testClassifyTierBundlePathWinsOverStdin(): void
    {
        $opts = (object) ['root_bundle_path' => '/var/www/horde', 'registry_in' => '-'];
        $this->assertSame('defaults', WebserverConfigOptions::classifyTier($opts));
    }

    public function testClassifyTierLiveRegistryByDefault(): void
    {
        $opts = (object) [];
        $this->assertSame('live-registry', WebserverConfigOptions::classifyTier($opts));
    }

    public function testClassifyTierLiveRegistryWithDefaultUrlOverride(): void
    {
        // --default-url without --root-bundle-path stays tier 1 (the
        // override layers on top of the live registry).
        $opts = (object) ['default_url' => 'http://localhost'];
        $this->assertSame('live-registry', WebserverConfigOptions::classifyTier($opts));
    }

    public function testExtractRelevantFlagsKeepsContentFlagsOnly(): void
    {
        $opts = (object) [
            'default_url' => 'http://localhost',
            'root_bundle_path' => '/var/www/horde',
            'php_handler' => 'tcp:127.0.0.1:9000',
            // These must be dropped: they do not change config content.
            'force' => true,
            'verbose' => true,
            'output_dir' => '/tmp/out',
        ];

        $flags = WebserverConfigOptions::extractRelevantFlags($opts);

        $this->assertSame(
            [
                '--default-url' => 'http://localhost',
                '--root-bundle-path' => '/var/www/horde',
                '--php-handler' => 'tcp:127.0.0.1:9000',
            ],
            $flags,
        );
    }

    public function testExtractRelevantFlagsSkipsEmptyAndUnset(): void
    {
        $opts = (object) ['default_url' => '', 'app_webroots' => null];
        $this->assertSame([], WebserverConfigOptions::extractRelevantFlags($opts));
    }
}
