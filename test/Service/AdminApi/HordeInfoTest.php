<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test\Service\AdminApi;

use Horde\Hordectl\Service\AdminApi\HordeInfo;
use PHPUnit\Framework\TestCase;

/**
 * Tests for HordeInfo
 */
#[\PHPUnit\Framework\Attributes\CoversClass(HordeInfo::class)]
class HordeInfoTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $info = new HordeInfo(
            version: '6.0.0-beta4',
            basePath: '/var/www/horde',
            webroot: '/horde',
            applications: ['horde', 'turba', 'imp']
        );

        $this->assertEquals('6.0.0-beta4', $info->version);
        $this->assertEquals('/var/www/horde', $info->basePath);
        $this->assertEquals('/horde', $info->webroot);
        $this->assertEquals(['horde', 'turba', 'imp'], $info->applications);
    }

    public function testFromApiResponse(): void
    {
        $data = [
            'version' => '6.0.0-beta4',
            'base_path' => '/var/www/horde',
            'webroot' => '/horde',
            'applications' => ['horde', 'turba', 'imp'],
        ];

        $info = HordeInfo::fromApiResponse($data);

        $this->assertEquals('6.0.0-beta4', $info->version);
        $this->assertEquals('/var/www/horde', $info->basePath);
        $this->assertEquals('/horde', $info->webroot);
        $this->assertEquals(['horde', 'turba', 'imp'], $info->applications);
    }

    public function testFromApiResponseWithMissingFields(): void
    {
        $data = [];

        $info = HordeInfo::fromApiResponse($data);

        $this->assertEquals('unknown', $info->version);
        $this->assertEquals('', $info->basePath);
        $this->assertEquals('', $info->webroot);
        $this->assertEquals([], $info->applications);
    }

    public function testFromApiResponseWithPartialData(): void
    {
        $data = [
            'version' => '6.0.0-beta4',
            'applications' => ['horde'],
        ];

        $info = HordeInfo::fromApiResponse($data);

        $this->assertEquals('6.0.0-beta4', $info->version);
        $this->assertEquals('', $info->basePath);
        $this->assertEquals('', $info->webroot);
        $this->assertEquals(['horde'], $info->applications);
    }
}
