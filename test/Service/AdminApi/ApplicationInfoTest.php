<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test\Service\AdminApi;

use Horde\Hordectl\Service\AdminApi\ApplicationInfo;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ApplicationInfo
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ApplicationInfo::class)]
class ApplicationInfoTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $info = new ApplicationInfo(
            name: 'turba',
            version: '5.0.0',
            status: 'active',
            active: true
        );

        $this->assertEquals('turba', $info->name);
        $this->assertEquals('5.0.0', $info->version);
        $this->assertEquals('active', $info->status);
        $this->assertTrue($info->active);
    }

    public function testFromApiResponse(): void
    {
        $data = [
            'name' => 'turba',
            'version' => '5.0.0',
            'status' => 'active',
            'active' => true,
        ];

        $info = ApplicationInfo::fromApiResponse($data);

        $this->assertEquals('turba', $info->name);
        $this->assertEquals('5.0.0', $info->version);
        $this->assertEquals('active', $info->status);
        $this->assertTrue($info->active);
    }

    public function testFromApiResponseWithMissingFields(): void
    {
        $data = [];

        $info = ApplicationInfo::fromApiResponse($data);

        $this->assertEquals('', $info->name);
        $this->assertEquals('unknown', $info->version);
        $this->assertEquals('unknown', $info->status);
        $this->assertFalse($info->active);
    }

    public function testFromApiResponseWithPartialData(): void
    {
        $data = [
            'name' => 'turba',
            'active' => true,
        ];

        $info = ApplicationInfo::fromApiResponse($data);

        $this->assertEquals('turba', $info->name);
        $this->assertEquals('unknown', $info->version);
        $this->assertEquals('unknown', $info->status);
        $this->assertTrue($info->active);
    }
}
