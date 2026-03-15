<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test\Service\AdminApi;

use Horde\Hordectl\Service\AdminApi\ApplicationInfo;
use Horde\Hordectl\Service\AdminApi\ApplicationList;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ApplicationList
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ApplicationList::class)]
class ApplicationListTest extends TestCase
{
    public function testConstructorWithMultipleApplications(): void
    {
        $app1 = new ApplicationInfo('turba', '5.0.0', 'active', true);
        $app2 = new ApplicationInfo('imp', '7.0.0', 'active', true);

        $list = new ApplicationList($app1, $app2);

        $this->assertCount(2, $list->toArray());
        $this->assertEquals(2, $list->count());
        $this->assertFalse($list->isEmpty());
    }

    public function testConstructorWithEmptyList(): void
    {
        $list = new ApplicationList();

        $this->assertCount(0, $list->toArray());
        $this->assertEquals(0, $list->count());
        $this->assertTrue($list->isEmpty());
    }

    public function testFromApiResponse(): void
    {
        $data = [
            [
                'name' => 'turba',
                'version' => '5.0.0',
                'status' => 'active',
                'active' => true,
            ],
            [
                'name' => 'imp',
                'version' => '7.0.0',
                'status' => 'active',
                'active' => true,
            ],
        ];

        $list = ApplicationList::fromApiResponse($data);

        $this->assertEquals(2, $list->count());
        $apps = $list->toArray();
        $this->assertEquals('turba', $apps[0]->name);
        $this->assertEquals('imp', $apps[1]->name);
    }

    public function testFromApiResponseWithEmptyArray(): void
    {
        $data = [];

        $list = ApplicationList::fromApiResponse($data);

        $this->assertEquals(0, $list->count());
        $this->assertTrue($list->isEmpty());
    }

    public function testToArray(): void
    {
        $app1 = new ApplicationInfo('turba', '5.0.0', 'active', true);
        $app2 = new ApplicationInfo('imp', '7.0.0', 'active', true);

        $list = new ApplicationList($app1, $app2);
        $array = $list->toArray();

        $this->assertIsArray($array);
        $this->assertCount(2, $array);
        $this->assertInstanceOf(ApplicationInfo::class, $array[0]);
        $this->assertInstanceOf(ApplicationInfo::class, $array[1]);
    }

    public function testCount(): void
    {
        $app1 = new ApplicationInfo('turba', '5.0.0', 'active', true);
        $app2 = new ApplicationInfo('imp', '7.0.0', 'active', true);
        $app3 = new ApplicationInfo('nag', '5.0.0', 'active', true);

        $list = new ApplicationList($app1, $app2, $app3);

        $this->assertEquals(3, $list->count());
    }

    public function testIsEmpty(): void
    {
        $emptyList = new ApplicationList();
        $this->assertTrue($emptyList->isEmpty());

        $app = new ApplicationInfo('turba', '5.0.0', 'active', true);
        $nonEmptyList = new ApplicationList($app);
        $this->assertFalse($nonEmptyList->isEmpty());
    }
}
