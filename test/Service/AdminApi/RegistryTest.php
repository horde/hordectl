<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category  Horde
 * @package   Hordectl
 */

namespace Horde\Hordectl\Test\Service\AdminApi;

use Horde\Hordectl\Service\AdminApi\Registry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Registry::class)]
class RegistryTest extends TestCase
{
    private function sampleSlots(): array
    {
        return [
            'default' => [
                'horde' => ['name' => 'Horde', 'status' => 'active'],
                'imp' => ['name' => 'IMP', 'webroot' => '/imp'],
            ],
            'foo.example.com' => [
                'imp' => ['webroot' => '/mail-foo'],
            ],
            'bar.example.com' => [
                'imp' => ['webroot' => '/mail-bar'],
            ],
        ];
    }

    public function testFromApiResponseRoundTripsSlots(): void
    {
        $slots = $this->sampleSlots();
        $registry = Registry::fromApiResponse($slots);

        $this->assertSame($slots, $registry->toArray());
    }

    public function testFromApiResponseWithEmptyPayload(): void
    {
        $registry = Registry::fromApiResponse([]);

        $this->assertSame([], $registry->toArray());
        $this->assertSame([], $registry->getSlotNames());
        $this->assertSame([], $registry->getVhosts());
    }

    public function testGetSlotNamesReturnsAllKeys(): void
    {
        $registry = Registry::fromApiResponse($this->sampleSlots());

        $this->assertSame(
            ['default', 'foo.example.com', 'bar.example.com'],
            $registry->getSlotNames(),
        );
    }

    public function testGetVhostsExcludesDefault(): void
    {
        $registry = Registry::fromApiResponse($this->sampleSlots());

        $this->assertSame(
            ['foo.example.com', 'bar.example.com'],
            $registry->getVhosts(),
        );
    }

    public function testGetVhostsOnDefaultOnlyPayload(): void
    {
        // A deployment with no vhost registry files gets back just
        // the default slot. getVhosts must return an empty list, not
        // fail on a missing 'default' key.
        $registry = Registry::fromApiResponse([
            'default' => ['horde' => ['name' => 'Horde']],
        ]);

        $this->assertSame(['default'], $registry->getSlotNames());
        $this->assertSame([], $registry->getVhosts());
    }
}
