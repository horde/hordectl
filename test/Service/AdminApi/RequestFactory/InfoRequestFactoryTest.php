<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test\Service\AdminApi\RequestFactory;

use Horde\Hordectl\Service\AdminApiConfig;
use Horde\Hordectl\Service\AdminApi\RequestFactory\InfoRequestFactory;
use Horde\Http\RequestFactory as PsrRequestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for InfoRequestFactory
 */
#[\PHPUnit\Framework\Attributes\CoversClass(InfoRequestFactory::class)]
class InfoRequestFactoryTest extends TestCase
{
    private AdminApiConfig $config;
    private PsrRequestFactory $requestFactory;

    protected function setUp(): void
    {
        $this->config = new AdminApiConfig(
            endpoint: 'http://localhost/horde',
            adminSecret: 'test-secret-123'
        );

        $this->requestFactory = new PsrRequestFactory();
    }

    public function testCreateRequest(): void
    {
        $factory = new InfoRequestFactory($this->config, $this->requestFactory);
        $request = $factory->create();

        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('http://localhost/horde/api/v1/admin/info', (string) $request->getUri());
    }

    public function testRequestHasCorrectHeaders(): void
    {
        $factory = new InfoRequestFactory($this->config, $this->requestFactory);
        $request = $factory->create();

        $this->assertEquals(['application/json'], $request->getHeader('Accept'));
        $this->assertEquals(['application/json'], $request->getHeader('Content-Type'));
        $this->assertEquals(['Bearer test-secret-123'], $request->getHeader('Authorization'));
    }

    public function testRequestUrlUsesConfigEndpoint(): void
    {
        $config = new AdminApiConfig(
            endpoint: 'https://horde.example.com',
            adminSecret: 'secret-456'
        );

        $factory = new InfoRequestFactory($config, $this->requestFactory);
        $request = $factory->create();

        $this->assertEquals('https://horde.example.com/api/v1/admin/info', (string) $request->getUri());
    }

    public function testRequestUsesConfigAdminSecret(): void
    {
        $config = new AdminApiConfig(
            endpoint: 'http://localhost/horde',
            adminSecret: 'custom-secret-789'
        );

        $factory = new InfoRequestFactory($config, $this->requestFactory);
        $request = $factory->create();

        $this->assertEquals(['Bearer custom-secret-789'], $request->getHeader('Authorization'));
    }
}
