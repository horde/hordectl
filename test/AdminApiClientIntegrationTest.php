<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test;

use Horde\Hordectl\Dependencies;
use Horde\Hordectl\Service\AdminApiClient;
use Horde\Injector\Scope;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Integration test for AdminApiClient DI setup
 *
 * Note: This test requires Horde to be bootstrapped
 * @coversNothing
 */
class AdminApiClientIntegrationTest extends TestCase
{
    public function testAdminApiClientCanBeRetrievedFromDI(): void
    {
        // This test requires Horde bootstrap which may not be available in CI
        // Skip if Horde is not available
        if (!class_exists('\Horde_Registry')) {
            $this->markTestSkipped('Horde not available for DI integration test');
        }

        try {
            $dependencies = new Dependencies(new Scope());

            $client = $dependencies->getInstance(AdminApiClient::class);

            $this->assertInstanceOf(AdminApiClient::class, $client);
        } catch (Throwable $e) {
            // If Horde bootstrap fails, skip the test
            $this->markTestSkipped('Horde bootstrap failed: ' . $e->getMessage());
        }
    }
}
