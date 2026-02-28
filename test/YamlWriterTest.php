<?php

namespace Horde\Hordectl\Test;

use Horde\Hordectl\YamlWriter;
use Horde\Yaml\Dumper;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class YamlWriterTest extends TestCase
{
    public function testNewYamlWriter()
    {
        $mockDumper = $this->createMock(Dumper::class);
        $this->assertInstanceOf(YamlWriter::class, new YamlWriter($mockDumper));
    }
}
