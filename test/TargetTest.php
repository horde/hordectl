<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test;

use Horde\Hordectl\Target;
use Horde\Hordectl\TargetType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Target::class)]
#[CoversClass(TargetType::class)]
class TargetTest extends TestCase
{
    public function testConstructorCreatesLocalTarget(): void
    {
        $target = new Target(
            name: 'test',
            type: TargetType::Local,
            hordeBase: '/path/to/horde',
            hordeInstallDir: '/path/to',
            endpoint: null,
            adminSecret: null
        );

        $this->assertSame('test', $target->name);
        $this->assertSame(TargetType::Local, $target->type);
        $this->assertSame('/path/to/horde', $target->hordeBase);
        $this->assertSame('/path/to', $target->hordeInstallDir);
        $this->assertNull($target->endpoint);
        $this->assertNull($target->adminSecret);
        $this->assertTrue($target->verifySsl);
        $this->assertFalse($target->autoDetected);
        $this->assertFalse($target->fromEnv);
    }

    public function testConstructorCreatesRemoteTarget(): void
    {
        $target = new Target(
            name: 'test',
            type: TargetType::Remote,
            hordeBase: null,
            hordeInstallDir: null,
            endpoint: 'https://example.com/horde',
            adminSecret: 'secret123'
        );

        $this->assertSame('test', $target->name);
        $this->assertSame(TargetType::Remote, $target->type);
        $this->assertNull($target->hordeBase);
        $this->assertNull($target->hordeInstallDir);
        $this->assertSame('https://example.com/horde', $target->endpoint);
        $this->assertSame('secret123', $target->adminSecret);
    }

    public function testFromArrayCreatesLocalTarget(): void
    {
        $config = [
            'type' => 'local',
            'horde_base' => '/path/to/horde',
            'horde_install_dir' => '/path/to',
            'description' => 'Test target',
        ];

        $target = Target::fromArray('test', $config);

        $this->assertSame('test', $target->name);
        $this->assertSame(TargetType::Local, $target->type);
        $this->assertSame('/path/to/horde', $target->hordeBase);
        $this->assertSame('/path/to', $target->hordeInstallDir);
        $this->assertSame('Test target', $target->description);
    }

    public function testFromArrayCreatesRemoteTarget(): void
    {
        $config = [
            'type' => 'remote',
            'endpoint' => 'https://example.com/horde',
            'admin_secret' => 'secret123',
            'verify_ssl' => false,
        ];

        $target = Target::fromArray('test', $config);

        $this->assertSame('test', $target->name);
        $this->assertSame(TargetType::Remote, $target->type);
        $this->assertSame('https://example.com/horde', $target->endpoint);
        $this->assertSame('secret123', $target->adminSecret);
        $this->assertFalse($target->verifySsl);
    }

    public function testToArrayReturnsCorrectStructure(): void
    {
        $target = new Target(
            name: 'test',
            type: TargetType::Local,
            hordeBase: '/path/to/horde',
            hordeInstallDir: '/path/to',
            endpoint: 'http://localhost/horde',
            adminSecret: '',
            verifySsl: true,
            description: 'Test target',
            autoDetected: true,
            fromEnv: false
        );

        $array = $target->toArray();

        $this->assertSame('local', $array['type']);
        $this->assertSame('/path/to/horde', $array['horde_base']);
        $this->assertSame('/path/to', $array['horde_install_dir']);
        $this->assertSame('http://localhost/horde', $array['endpoint']);
        $this->assertSame('', $array['admin_secret']);
        $this->assertSame('Test target', $array['description']);
        $this->assertTrue($array['auto_detected']);
        $this->assertArrayNotHasKey('from_env', $array); // Not included when false
        $this->assertArrayNotHasKey('verify_ssl', $array); // Not included when true (default)
    }

    public function testIsLocalReturnsTrueForLocalTarget(): void
    {
        $target = new Target(
            name: 'test',
            type: TargetType::Local,
            hordeBase: '/path/to/horde',
            hordeInstallDir: '/path/to',
            endpoint: null,
            adminSecret: null
        );

        $this->assertTrue($target->isLocal());
        $this->assertFalse($target->isRemote());
    }

    public function testIsRemoteReturnsTrueForRemoteTarget(): void
    {
        $target = new Target(
            name: 'test',
            type: TargetType::Remote,
            hordeBase: null,
            hordeInstallDir: null,
            endpoint: 'https://example.com/horde',
            adminSecret: 'secret'
        );

        $this->assertFalse($target->isLocal());
        $this->assertTrue($target->isRemote());
    }

    public function testSupportsFilesystemCommandsForLocalTarget(): void
    {
        $target = new Target(
            name: 'test',
            type: TargetType::Local,
            hordeBase: '/path/to/horde',
            hordeInstallDir: '/path/to',
            endpoint: null,
            adminSecret: null
        );

        $this->assertTrue($target->supportsFilesystemCommands());
    }

    public function testSupportsFilesystemCommandsForRemoteTarget(): void
    {
        $target = new Target(
            name: 'test',
            type: TargetType::Remote,
            hordeBase: null,
            hordeInstallDir: null,
            endpoint: 'https://example.com/horde',
            adminSecret: 'secret'
        );

        $this->assertFalse($target->supportsFilesystemCommands());
    }

    public function testSupportsApiCommandsForRemoteTarget(): void
    {
        $target = new Target(
            name: 'test',
            type: TargetType::Remote,
            hordeBase: null,
            hordeInstallDir: null,
            endpoint: 'https://example.com/horde',
            adminSecret: 'secret'
        );

        $this->assertTrue($target->supportsApiCommands());
    }

    public function testSupportsApiCommandsForLocalTargetWithoutEndpoint(): void
    {
        $target = new Target(
            name: 'test',
            type: TargetType::Local,
            hordeBase: '/path/to/horde',
            hordeInstallDir: '/path/to',
            endpoint: null,
            adminSecret: null
        );

        $this->assertFalse($target->supportsApiCommands());
    }

    public function testSupportsApiCommandsForLocalTargetWithEndpoint(): void
    {
        $target = new Target(
            name: 'test',
            type: TargetType::Local,
            hordeBase: '/path/to/horde',
            hordeInstallDir: '/path/to',
            endpoint: 'http://localhost/horde',
            adminSecret: ''
        );

        $this->assertTrue($target->supportsApiCommands());
    }

    public function testGetLocationStringForLocalTarget(): void
    {
        $target = new Target(
            name: 'test',
            type: TargetType::Local,
            hordeBase: '/path/to/horde',
            hordeInstallDir: '/path/to',
            endpoint: null,
            adminSecret: null
        );

        $this->assertSame('/path/to', $target->getLocationString());
    }

    public function testGetLocationStringForLocalTargetWithEndpoint(): void
    {
        $target = new Target(
            name: 'test',
            type: TargetType::Local,
            hordeBase: '/path/to/horde',
            hordeInstallDir: '/path/to',
            endpoint: 'http://localhost/horde',
            adminSecret: ''
        );

        $this->assertSame('/path/to (+ API)', $target->getLocationString());
    }

    public function testGetLocationStringForRemoteTarget(): void
    {
        $target = new Target(
            name: 'test',
            type: TargetType::Remote,
            hordeBase: null,
            hordeInstallDir: null,
            endpoint: 'https://example.com/horde',
            adminSecret: 'secret'
        );

        $this->assertSame('https://example.com/horde', $target->getLocationString());
    }

    public function testGetAbbreviatedLocationReturnsFullStringWhenShort(): void
    {
        $target = new Target(
            name: 'test',
            type: TargetType::Local,
            hordeBase: '/path/to/horde',
            hordeInstallDir: '/short',
            endpoint: null,
            adminSecret: null
        );

        $this->assertSame('/short', $target->getAbbreviatedLocation(50));
    }

    public function testGetAbbreviatedLocationTruncatesLongString(): void
    {
        $target = new Target(
            name: 'test',
            type: TargetType::Local,
            hordeBase: '/very/long/path/to/horde/installation',
            hordeInstallDir: '/very/long/path/to/horde/installation',
            endpoint: null,
            adminSecret: null
        );

        $abbreviated = $target->getAbbreviatedLocation(20);

        $this->assertLessThanOrEqual(20, strlen($abbreviated));
        $this->assertStringEndsWith('...', $abbreviated);
    }
}
