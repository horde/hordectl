<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Test\Service\WebserverConfig;

use Horde\Hordectl\Service\WebserverConfig\GenerationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the reproducer command rendering in GenerationContext.
 */
#[CoversClass(GenerationContext::class)]
class GenerationContextTest extends TestCase
{
    public function testReproducerRendersFlagsInOrder(): void
    {
        $context = new GenerationContext(
            tier: 'defaults',
            flavor: 'nginx',
            flags: [
                '--default-url' => 'http://localhost',
                '--root-bundle-path' => '/var/www/horde',
            ],
        );

        $this->assertSame(
            'hordectl webserver-config nginx --default-url=http://localhost --root-bundle-path=/var/www/horde',
            $context->reproducer(),
        );
    }

    public function testReproducerRendersStoreTrueFlagWithoutValue(): void
    {
        $context = new GenerationContext(
            tier: 'live-registry',
            flavor: 'apache-vhost',
            flags: ['--registry-in' => true],
        );

        $this->assertSame(
            'hordectl webserver-config apache-vhost --registry-in',
            $context->reproducer(),
        );
    }

    public function testReproducerSkipsEmptyAndFalseFlags(): void
    {
        $context = new GenerationContext(
            tier: 'live-registry',
            flavor: 'nginx',
            flags: [
                '--default-url' => '',
                '--force' => false,
                '--php-handler' => 'tcp:127.0.0.1:9000',
            ],
        );

        $this->assertSame(
            'hordectl webserver-config nginx --php-handler=tcp:127.0.0.1:9000',
            $context->reproducer(),
        );
    }

    public function testReproducerShellQuotesValuesWithSpaces(): void
    {
        $context = new GenerationContext(
            tier: 'defaults',
            flavor: 'nginx',
            flags: ['--app-webroots' => 'imp|http://x/a b'],
        );

        $this->assertSame(
            "hordectl webserver-config nginx --app-webroots='imp|http://x/a b'",
            $context->reproducer(),
        );
    }
}
