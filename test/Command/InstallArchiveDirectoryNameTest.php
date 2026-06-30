<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Test\Command;

use Horde\Hordectl\Command\Install;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for {@see Install}'s archive-name prediction and the
 * defensive single-top-level-directory discovery used by extractArchive().
 *
 * Both methods exercised here are pure functions (`getArchiveDirectoryName`
 * is purely arithmetic on strings; `findSingleTopLevelDirectory` reads but
 * does not modify the filesystem). Tests reach them via Reflection so the
 * Install command's constructor — which wires several services — does not
 * have to be built.
 *
 * Pins the fix for issue #13: a `v`-prefixed semver tag like `v1.1.1RC1`
 * downloads correctly but extracts into a directory whose name has the
 * leading `v` stripped (`bundle-1.1.1RC1`). The predictor must mirror
 * GitHub's rule, and the extraction must fall back to discovery when the
 * predictor disagrees with reality.
 */
#[CoversClass(Install::class)]
class InstallArchiveDirectoryNameTest extends TestCase
{
    private ReflectionMethod $getArchiveDirectoryName;
    private ReflectionMethod $findSingleTopLevelDirectory;
    private Install $instance;

    /** Scratch directory created per-test for filesystem-touching cases. */
    private ?string $tmpDir = null;

    protected function setUp(): void
    {
        $ref = new ReflectionClass(Install::class);
        $this->instance = $ref->newInstanceWithoutConstructor();

        $this->getArchiveDirectoryName = $ref->getMethod('getArchiveDirectoryName');
        $this->getArchiveDirectoryName->setAccessible(true);

        $this->findSingleTopLevelDirectory = $ref->getMethod('findSingleTopLevelDirectory');
        $this->findSingleTopLevelDirectory->setAccessible(true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            $this->recursiveRemove($this->tmpDir);
        }
    }

    // ---------------------------------------------------------------
    // getArchiveDirectoryName — the prediction layer (issue #13)
    // ---------------------------------------------------------------

    #[Test]
    public function testStripsLeadingVFromSemverTag(): void
    {
        // The bundle's v1.1.1RC1 tag is the exact case from issue #13.
        self::assertSame(
            'bundle-1.1.1RC1',
            $this->getArchiveDirectoryName->invoke($this->instance, 'bundle', 'v1.1.1RC1'),
        );
    }

    #[Test]
    public function testStripsLeadingVFromOtherSemverShapes(): void
    {
        self::assertSame(
            'bundle-2.0.0',
            $this->getArchiveDirectoryName->invoke($this->instance, 'bundle', 'v2.0.0'),
        );
        self::assertSame(
            'bundle-2.0.0-beta1',
            $this->getArchiveDirectoryName->invoke($this->instance, 'bundle', 'v2.0.0-beta1'),
        );
        self::assertSame(
            'bundle-10.5.0',
            $this->getArchiveDirectoryName->invoke($this->instance, 'bundle', 'v10.5.0'),
        );
    }

    #[Test]
    public function testLeavesUnprefixedTagsUntouched(): void
    {
        // The bundle's pre-RC1 tag history was unprefixed (1.0.0..1.0.3).
        // Those continue to work as before.
        self::assertSame(
            'bundle-1.0.3',
            $this->getArchiveDirectoryName->invoke($this->instance, 'bundle', '1.0.3'),
        );
    }

    #[Test]
    public function testLeavesNonVersionVTagsUntouched(): void
    {
        // Tags that happen to start with 'v' but are not semver-like
        // must NOT be stripped. GitHub does not strip them either.
        self::assertSame(
            'bundle-vendor',
            $this->getArchiveDirectoryName->invoke($this->instance, 'bundle', 'vendor'),
        );
        self::assertSame(
            'bundle-vfoo',
            $this->getArchiveDirectoryName->invoke($this->instance, 'bundle', 'vfoo'),
        );
        self::assertSame(
            'bundle-v-something',
            $this->getArchiveDirectoryName->invoke($this->instance, 'bundle', 'v-something'),
        );
    }

    #[Test]
    public function testReplacesSlashWithDash(): void
    {
        // Branch names with slashes get sanitized: GitHub's archive URL
        // for refs/heads/feat/foo extracts to bundle-feat-foo/.
        self::assertSame(
            'bundle-feat-foo',
            $this->getArchiveDirectoryName->invoke($this->instance, 'bundle', 'feat/foo'),
        );
    }

    #[Test]
    public function testSlashSanitizationAndVStrippingCompose(): void
    {
        // A hypothetical v-prefixed branch name with a slash exercises
        // both rules at once. (Unusual, but the regex applies AFTER
        // slash replacement, so this is consistent.)
        self::assertSame(
            'bundle-1.0-rc',
            $this->getArchiveDirectoryName->invoke($this->instance, 'bundle', 'v1.0/rc'),
        );
    }

    // ---------------------------------------------------------------
    // findSingleTopLevelDirectory — the defensive discovery layer
    // ---------------------------------------------------------------

    #[Test]
    public function testFindSingleTopLevelDirectoryReturnsSoleSubdir(): void
    {
        $tmp = $this->makeTmpDir();
        mkdir($tmp . '/bundle-actually-1.2.3');

        $result = $this->findSingleTopLevelDirectory->invoke($this->instance, $tmp);

        self::assertSame($tmp . '/bundle-actually-1.2.3', $result);
    }

    #[Test]
    public function testFindSingleTopLevelDirectoryReturnsNullForEmptyArchive(): void
    {
        $tmp = $this->makeTmpDir();

        $result = $this->findSingleTopLevelDirectory->invoke($this->instance, $tmp);

        self::assertNull($result);
    }

    #[Test]
    public function testFindSingleTopLevelDirectoryReturnsNullForMultipleDirs(): void
    {
        // Two top-level directories is ambiguous; we refuse to guess.
        $tmp = $this->makeTmpDir();
        mkdir($tmp . '/a');
        mkdir($tmp . '/b');

        $result = $this->findSingleTopLevelDirectory->invoke($this->instance, $tmp);

        self::assertNull($result);
    }

    #[Test]
    public function testFindSingleTopLevelDirectoryReturnsNullForFilesAtTopLevel(): void
    {
        // A loose file at the top level breaks the single-root shape.
        $tmp = $this->makeTmpDir();
        mkdir($tmp . '/some-dir');
        file_put_contents($tmp . '/stray.txt', 'noise');

        $result = $this->findSingleTopLevelDirectory->invoke($this->instance, $tmp);

        self::assertNull($result);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeTmpDir(): string
    {
        $this->tmpDir = sys_get_temp_dir() . '/hordectl_install_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);
        return $this->tmpDir;
    }

    private function recursiveRemove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->recursiveRemove($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
