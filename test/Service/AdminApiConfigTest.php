<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test\Service;

use Horde\Hordectl\Service\AdminApiConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for AdminApiConfig
 */
#[\PHPUnit\Framework\Attributes\CoversClass(AdminApiConfig::class)]
class AdminApiConfigTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $config = new AdminApiConfig(
            endpoint: 'http://localhost/horde',
            adminSecret: 'test-secret-123',
            apiVersion: 'v2'
        );

        $this->assertEquals('http://localhost/horde', $config->endpoint);
        $this->assertEquals('test-secret-123', $config->adminSecret);
        $this->assertEquals('v2', $config->apiVersion);
    }

    public function testConstructorDefaultApiVersion(): void
    {
        $config = new AdminApiConfig(
            endpoint: 'http://localhost/horde',
            adminSecret: 'test-secret-123'
        );

        $this->assertEquals('v1', $config->apiVersion);
    }

    public function testGetApiBaseUrl(): void
    {
        $config = new AdminApiConfig(
            endpoint: 'http://localhost/horde',
            adminSecret: 'test-secret-123'
        );

        $this->assertEquals('http://localhost/horde/api/v1', $config->getApiBaseUrl());
    }

    public function testGetApiBaseUrlRemovesTrailingSlash(): void
    {
        $config = new AdminApiConfig(
            endpoint: 'http://localhost/horde/',
            adminSecret: 'test-secret-123'
        );

        $this->assertEquals('http://localhost/horde/api/v1', $config->getApiBaseUrl());
    }

    public function testGetApiBaseUrlWithCustomVersion(): void
    {
        $config = new AdminApiConfig(
            endpoint: 'http://localhost/horde',
            adminSecret: 'test-secret-123',
            apiVersion: 'v2'
        );

        $this->assertEquals('http://localhost/horde/api/v2', $config->getApiBaseUrl());
    }

    public function testImmutability(): void
    {
        $config = new AdminApiConfig(
            endpoint: 'http://localhost/horde',
            adminSecret: 'test-secret-123'
        );

        // Attempting to modify readonly property should fail at parse time
        // This test verifies the properties are readonly
        $reflection = new ReflectionClass($config);
        $endpointProp = $reflection->getProperty('endpoint');
        $this->assertTrue($endpointProp->isReadOnly());

        $secretProp = $reflection->getProperty('adminSecret');
        $this->assertTrue($secretProp->isReadOnly());

        $versionProp = $reflection->getProperty('apiVersion');
        $this->assertTrue($versionProp->isReadOnly());
    }
}
