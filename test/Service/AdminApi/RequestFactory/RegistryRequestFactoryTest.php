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

namespace Horde\Hordectl\Test\Service\AdminApi\RequestFactory;

use Horde\Hordectl\Service\AdminApiConfig;
use Horde\Hordectl\Service\AdminApi\RequestFactory\RegistryRequestFactory;
use Horde\Http\RequestFactory as PsrRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistryRequestFactory::class)]
class RegistryRequestFactoryTest extends TestCase
{
    private AdminApiConfig $config;
    private PsrRequestFactory $requestFactory;

    protected function setUp(): void
    {
        $this->config = new AdminApiConfig(
            endpoint: 'http://localhost/horde',
            adminSecret: 'test-secret-123',
        );
        $this->requestFactory = new PsrRequestFactory();
    }

    public function testCreateRequestIsGet(): void
    {
        $factory = new RegistryRequestFactory($this->config, $this->requestFactory);
        $request = $factory->create();

        // Registry is a pure read. GET, not POST (unlike the older
        // introspection endpoints which inherited POST from a body-parser
        // convention that never applied to reads without input).
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(
            'http://localhost/horde/api/v1/admin/registry',
            (string) $request->getUri(),
        );
    }

    public function testRequestHasCorrectHeaders(): void
    {
        $factory = new RegistryRequestFactory($this->config, $this->requestFactory);
        $request = $factory->create();

        $this->assertSame(['application/json'], $request->getHeader('Accept'));
        $this->assertSame(['Bearer test-secret-123'], $request->getHeader('Authorization'));

        // GET requests carry no body. No Content-Type header expected.
        $this->assertSame([], $request->getHeader('Content-Type'));
    }

    public function testRequestUrlUsesConfigEndpoint(): void
    {
        $config = new AdminApiConfig(
            endpoint: 'https://horde.example.com',
            adminSecret: 'secret-456',
        );

        $factory = new RegistryRequestFactory($config, $this->requestFactory);
        $request = $factory->create();

        $this->assertSame(
            'https://horde.example.com/api/v1/admin/registry',
            (string) $request->getUri(),
        );
    }

    public function testRequestUsesConfigAdminSecret(): void
    {
        $config = new AdminApiConfig(
            endpoint: 'http://localhost/horde',
            adminSecret: 'custom-secret-789',
        );

        $factory = new RegistryRequestFactory($config, $this->requestFactory);
        $request = $factory->create();

        $this->assertSame(['Bearer custom-secret-789'], $request->getHeader('Authorization'));
    }
}
