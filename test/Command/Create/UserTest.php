<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test\Command\Create;

use Horde\Argv\Parser;
use Horde\Cli\Cli as HordeCli;
use Horde\Hordectl\Command\Create\User;
use Horde\Hordectl\Dependencies;
use Horde\Hordectl\Output;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Test the Create User command
 *
 * Covers surviving methods only. The previous test iteration exercised
 * granular helpers (getUsername, getPassword, validatePasswordOptions,
 * displayInteractiveHeader, displayGeneratedPassword, isInteractive) that
 * were folded into gatherUserData() / promptPassword() / validateUserData()
 * during a refactor. Tests for those methods were removed; retesting the
 * new surface at the same granularity would require simulating a much
 * bigger fixture (the whole gatherUserData interactive-prompt flow with
 * ordered writeln/prompt/passwordPrompt calls).
 */
#[CoversClass(User::class)]
class UserTest extends TestCase
{
    /**
     * Build an injector that hands out $cli for HordeCli and a Parser stub.
     *
     * Kept as a helper (rather than shared in setUp) so each test can pass
     * whatever CLI double it needs — mock when interactions are verified,
     * stub when not.
     */
    private function injectorWithCli(HordeCli $cli): Dependencies|MockObject
    {
        $injector = $this->createStub(Dependencies::class);
        $injector->method('getInstance')
            ->willReturnCallback(function ($class) use ($cli) {
                if ($class === HordeCli::class) {
                    return $cli;
                }
                if ($class === Parser::class) {
                    return $this->createStub(Parser::class);
                }
                return null;
            });
        $injector->method('createOutput')
            ->willReturn(new Output($cli));

        return $injector;
    }

    public function testConstructorCreatesUserInstance(): void
    {
        $user = new User($this->injectorWithCli($this->createStub(HordeCli::class)));
        $this->assertInstanceOf(User::class, $user);
    }

    public function testGenerateRandomPasswordReturnsRequestedLength(): void
    {
        $user = new User($this->injectorWithCli($this->createStub(HordeCli::class)));

        $method = new ReflectionMethod($user, 'generateRandomPassword');
        $password = $method->invoke($user, 24);

        $this->assertIsString($password);
        $this->assertSame(24, strlen($password));
    }

    public function testGenerateRandomPasswordReturnsDistinctValues(): void
    {
        $user = new User($this->injectorWithCli($this->createStub(HordeCli::class)));

        $method = new ReflectionMethod($user, 'generateRandomPassword');
        $a = $method->invoke($user, 16);
        $b = $method->invoke($user, 16);

        $this->assertNotSame($a, $b, 'random-password generator produced identical output twice');
    }

    public function testPromptPasswordAsksTwiceAndReturnsMatch(): void
    {
        $cli = $this->createMock(HordeCli::class);
        $cli->expects($this->exactly(2))
            ->method('passwordPrompt')
            ->willReturn('matching-pass');

        $user = new User($this->injectorWithCli($cli));

        $method = new ReflectionMethod($user, 'promptPassword');
        $this->assertSame('matching-pass', $method->invoke($user));
    }

    public function testPromptPasswordFailsOnMismatch(): void
    {
        $cli = $this->createMock(HordeCli::class);
        $callCount = 0;
        $cli->expects($this->exactly(2))
            ->method('passwordPrompt')
            ->willReturnCallback(function () use (&$callCount) {
                return $callCount++ === 0 ? 'firstpass' : 'secondpass';
            });
        $cli->expects($this->once())
            ->method('fatal')
            ->willReturnCallback(function ($message) {
                throw new RuntimeException($message);
            });

        $user = new User($this->injectorWithCli($cli));

        $this->expectException(RuntimeException::class);

        $method = new ReflectionMethod($user, 'promptPassword');
        $method->invoke($user);
    }
}
