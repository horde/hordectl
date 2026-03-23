<?php

declare(strict_types=1);

namespace Horde\Hordectl\Test\Command\Create;

use Horde\Hordectl\Command\Create\User;
use Horde\Hordectl\Dependencies;
use Horde\Hordectl\Service\AdminApiClient;
use Horde_Cli;
use Horde\Argv\Parser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use stdClass;

/**
 * Test the Create User command
 */
#[CoversClass(User::class)]
class UserTest extends TestCase
{
    private $mockInjector;
    private $mockCli;
    private $mockParser;
    private $mockApiClient;
    private $command;
    private array $cliCalls = [];

    protected function setUp(): void
    {
        $this->mockInjector = $this->createMock(Dependencies::class);
        $this->mockCli = $this->createMock(Horde_Cli::class);
        $this->mockParser = $this->createMock(Parser::class);
        $this->mockApiClient = $this->createMock(AdminApiClient::class);
        $this->cliCalls = [];

        // Capture all writeln calls
        $this->mockCli->method('writeln')
            ->willReturnCallback(function ($text = '') {
                $this->cliCalls[] = ['method' => 'writeln', 'args' => [$text]];
            });

        // Capture all prompt calls
        $this->mockCli->method('prompt')
            ->willReturnCallback(function ($prompt, $choices = null, $default = null) {
                $this->cliCalls[] = ['method' => 'prompt', 'args' => [$prompt, $choices, $default]];
                return 'testuser';  // Default return
            });

        // Capture all passwordPrompt calls
        $this->mockCli->method('passwordPrompt')
            ->willReturnCallback(function ($prompt) {
                $this->cliCalls[] = ['method' => 'passwordPrompt', 'args' => [$prompt]];
                return 'testpass';  // Default return
            });

        // Capture message calls
        $this->mockCli->method('message')
            ->willReturnCallback(function ($text, $type) {
                $this->cliCalls[] = ['method' => 'message', 'args' => [$text, $type]];
            });

        // Capture fatal calls (throw exception to simulate exit)
        $this->mockCli->method('fatal')
            ->willReturnCallback(function ($message) {
                $this->cliCalls[] = ['method' => 'fatal', 'args' => [$message]];
                throw new RuntimeException($message);
            });

        // Capture yellow calls
        $this->mockCli->method('yellow')
            ->willReturnCallback(function ($text) {
                $this->cliCalls[] = ['method' => 'yellow', 'args' => [$text]];
                return "\033[33m" . $text . "\033[0m";  // ANSI yellow
            });

        // Setup injector to return our mocks
        $this->mockInjector->method('getInstance')
            ->willReturnCallback(function ($class) {
                if ($class === '\Horde_Cli') {
                    return $this->mockCli;
                }
                if ($class === Parser::class) {
                    return $this->mockParser;
                }
                return null;
            });
    }

    /**
     * Helper to find calls by method name
     */
    private function findCalls(string $method): array
    {
        return array_filter($this->cliCalls, fn($call) => $call['method'] === $method);
    }

    /**
     * Helper to get call arguments for a specific method
     */
    private function getCallArgs(string $method, int $index = 0): ?array
    {
        $calls = array_values($this->findCalls($method));
        return $calls[$index]['args'] ?? null;
    }

    public function testConstructorCreatesUserInstance(): void
    {
        $user = new User($this->mockInjector);
        $this->assertInstanceOf(User::class, $user);
    }

    public function testDisplayInteractiveHeaderShowsCorrectText(): void
    {
        $user = new User($this->mockInjector);

        // Use reflection to call protected method
        $method = new \ReflectionMethod($user, 'displayInteractiveHeader');
        $method->invoke($user);

        // Verify exact sequence of writeln calls
        $writelnCalls = $this->findCalls('writeln');
        $this->assertCount(4, $writelnCalls);

        $calls = array_values($writelnCalls);
        $this->assertEquals('', $calls[0]['args'][0]);  // Blank line
        $this->assertEquals('Create new user', $calls[1]['args'][0]);
        $this->assertEquals('===============', $calls[2]['args'][0]);
        $this->assertEquals('', $calls[3]['args'][0]);  // Blank line
    }

    public function testGetUsernameReturnsOptionWhenProvided(): void
    {
        $user = new User($this->mockInjector);

        $opts = new stdClass();
        $opts->username = 'alice';

        $method = new \ReflectionMethod($user, 'getUsername');
        $result = $method->invoke($user, $opts, false);

        $this->assertEquals('alice', $result);

        // Should NOT have prompted
        $this->assertCount(0, $this->findCalls('prompt'));
    }

    public function testGetUsernamePromptsWhenInteractiveAndNoOption(): void
    {
        // Create fresh mocks for this test
        $mockCli = $this->createMock(Horde_Cli::class);
        $mockCli->expects($this->once())
            ->method('prompt')
            ->with('Username:')
            ->willReturn('bob');

        // Create user with our specific mock
        $mockInjector = $this->createMock(Dependencies::class);
        $mockParser = $this->createMock(Parser::class);
        $mockInjector->method('getInstance')
            ->willReturnCallback(function ($class) use ($mockCli, $mockParser) {
                if ($class === '\Horde_Cli') {
                    return $mockCli;
                }
                if ($class === Parser::class) {
                    return $mockParser;
                }
                return null;
            });

        $user = new User($mockInjector);

        $opts = new stdClass();

        $method = new \ReflectionMethod($user, 'getUsername');
        $result = $method->invoke($user, $opts, true);

        $this->assertEquals('bob', $result);
    }

    public function testGetUsernameFailsWhenNonInteractiveAndNoOption(): void
    {
        $user = new User($this->mockInjector);

        $opts = new stdClass();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--username required');

        $method = new \ReflectionMethod($user, 'getUsername');
        $method->invoke($user, $opts, false);
    }

    public function testValidatePasswordOptionsAcceptsNoOptions(): void
    {
        $user = new User($this->mockInjector);

        $opts = new stdClass();

        // Should not throw
        $method = new \ReflectionMethod($user, 'validatePasswordOptions');
        $method->invoke($user, $opts);

        $this->assertTrue(true);  // If we get here, test passed
    }

    public function testValidatePasswordOptionsAcceptsOnlyPassword(): void
    {
        $user = new User($this->mockInjector);

        $opts = new stdClass();
        $opts->password = 'secret';

        // Should not throw
        $method = new \ReflectionMethod($user, 'validatePasswordOptions');
        $method->invoke($user, $opts);

        $this->assertTrue(true);
    }

    public function testValidatePasswordOptionsAcceptsOnlyRandomPassword(): void
    {
        $user = new User($this->mockInjector);

        $opts = new stdClass();
        $opts->random_password = true;

        // Should not throw
        $method = new \ReflectionMethod($user, 'validatePasswordOptions');
        $method->invoke($user, $opts);

        $this->assertTrue(true);
    }

    public function testValidatePasswordOptionsFailsWithBothOptions(): void
    {
        $user = new User($this->mockInjector);

        $opts = new stdClass();
        $opts->password = 'secret';
        $opts->random_password = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot specify both --password and --random-password');

        $method = new \ReflectionMethod($user, 'validatePasswordOptions');
        $method->invoke($user, $opts);
    }

    public function testGenerateRandomPasswordReturnsCorrectLength(): void
    {
        $user = new User($this->mockInjector);

        $method = new \ReflectionMethod($user, 'generateRandomPassword');
        $password = $method->invoke($user, 16);

        $this->assertEquals(16, strlen($password));
    }

    public function testGenerateRandomPasswordReturnsDifferentPasswords(): void
    {
        $user = new User($this->mockInjector);

        $method = new \ReflectionMethod($user, 'generateRandomPassword');
        $password1 = $method->invoke($user);
        $password2 = $method->invoke($user);

        $this->assertNotEquals($password1, $password2);
    }

    public function testGetPasswordReturnsExplicitPassword(): void
    {
        $user = new User($this->mockInjector);

        $opts = new stdClass();
        $opts->password = 'mypassword';

        $method = new \ReflectionMethod($user, 'getPassword');
        $result = $method->invoke($user, $opts, false);

        $this->assertEquals('mypassword', $result['password']);
        $this->assertFalse($result['generated']);

        // Should NOT have prompted
        $this->assertCount(0, $this->findCalls('passwordPrompt'));
    }

    public function testGetPasswordGeneratesRandomPassword(): void
    {
        $user = new User($this->mockInjector);

        $opts = new stdClass();
        $opts->random_password = true;

        $method = new \ReflectionMethod($user, 'getPassword');
        $result = $method->invoke($user, $opts, false);

        $this->assertNotEmpty($result['password']);
        $this->assertEquals(16, strlen($result['password']));
        $this->assertTrue($result['generated']);

        // Should NOT have prompted
        $this->assertCount(0, $this->findCalls('passwordPrompt'));
    }

    public function testGetPasswordPromptsWhenInteractive(): void
    {
        // Create fresh mocks for this test
        $mockCli = $this->createMock(Horde_Cli::class);
        $mockCli->expects($this->exactly(2))
            ->method('passwordPrompt')
            ->willReturn('interactive-pass');

        // Create user with our specific mock
        $mockInjector = $this->createMock(Dependencies::class);
        $mockParser = $this->createMock(Parser::class);
        $mockInjector->method('getInstance')
            ->willReturnCallback(function ($class) use ($mockCli, $mockParser) {
                if ($class === '\Horde_Cli') {
                    return $mockCli;
                }
                if ($class === Parser::class) {
                    return $mockParser;
                }
                return null;
            });

        $user = new User($mockInjector);

        $opts = new stdClass();

        $method = new \ReflectionMethod($user, 'getPassword');
        $result = $method->invoke($user, $opts, true);

        $this->assertEquals('interactive-pass', $result['password']);
        $this->assertFalse($result['generated']);
    }

    public function testGetPasswordFailsWhenNonInteractiveAndNoOption(): void
    {
        $user = new User($this->mockInjector);

        $opts = new stdClass();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--password or --random-password required');

        $method = new \ReflectionMethod($user, 'getPassword');
        $method->invoke($user, $opts, false);
    }

    public function testDisplayGeneratedPasswordShowsCorrectOutput(): void
    {
        $user = new User($this->mockInjector);

        $method = new \ReflectionMethod($user, 'displayGeneratedPassword');
        $method->invoke($user, 'xK9mP2nQ4rT7sW1v');

        // Should have 3 writeln calls + 1 yellow call
        $writelnCalls = $this->findCalls('writeln');
        $this->assertGreaterThanOrEqual(3, count($writelnCalls));

        $calls = array_values($writelnCalls);
        // Find the call with generated password
        $found = false;
        foreach ($calls as $call) {
            if (str_contains($call['args'][0], 'Generated password: xK9mP2nQ4rT7sW1v')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Should display generated password');

        // Should have called yellow with warning
        $yellowCalls = $this->findCalls('yellow');
        $this->assertGreaterThanOrEqual(1, count($yellowCalls));
    }

    public function testIsInteractiveDetectsTTY(): void
    {
        $user = new User($this->mockInjector);

        $method = new \ReflectionMethod($user, 'isInteractive');
        $result = $method->invoke($user);

        // In test environment, this depends on how tests are run
        // Just verify it returns a boolean
        $this->assertIsBool($result);
    }

    public function testPromptPasswordCallsPasswordPromptTwice(): void
    {
        // Create fresh mocks for this test
        $mockCli = $this->createMock(Horde_Cli::class);
        $mockCli->expects($this->exactly(2))
            ->method('passwordPrompt')
            ->willReturn('matching-pass');

        // Create user with our specific mock
        $mockInjector = $this->createMock(Dependencies::class);
        $mockParser = $this->createMock(Parser::class);
        $mockInjector->method('getInstance')
            ->willReturnCallback(function ($class) use ($mockCli, $mockParser) {
                if ($class === '\Horde_Cli') {
                    return $mockCli;
                }
                if ($class === Parser::class) {
                    return $mockParser;
                }
                return null;
            });

        $user = new User($mockInjector);

        $method = new \ReflectionMethod($user, 'promptPassword');
        $result = $method->invoke($user);

        $this->assertEquals('matching-pass', $result);
    }

    public function testPromptPasswordFailsOnMismatch(): void
    {
        // Create fresh mocks for this test
        $mockCli = $this->createMock(Horde_Cli::class);
        $callCount = 0;
        $mockCli->expects($this->exactly(2))
            ->method('passwordPrompt')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return $callCount === 1 ? 'pass1' : 'pass2';
            });
        $mockCli->expects($this->once())
            ->method('fatal')
            ->with($this->stringContains('Passwords do not match'))
            ->willThrowException(new RuntimeException('Passwords do not match'));

        // Create user with our specific mock
        $mockInjector = $this->createMock(Dependencies::class);
        $mockParser = $this->createMock(Parser::class);
        $mockInjector->method('getInstance')
            ->willReturnCallback(function ($class) use ($mockCli, $mockParser) {
                if ($class === '\Horde_Cli') {
                    return $mockCli;
                }
                if ($class === Parser::class) {
                    return $mockParser;
                }
                return null;
            });

        $user = new User($mockInjector);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Passwords do not match');

        $method = new \ReflectionMethod($user, 'promptPassword');
        $method->invoke($user);
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Expected '$haystack' to contain '$needle'"
        );
    }
}
