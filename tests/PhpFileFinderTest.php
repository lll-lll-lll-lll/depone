<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\TestCase;
use Depone\Internal\Resolver\PhpFileFinder;

/**
 * Unit tests for PhpFileFinder.
 *
 * Covered behavior:
 *   - recursive discovery of `.php` files, including nested directories
 *   - case-insensitive extension matching (e.g. `.PHP`)
 *   - non-`.php` files are excluded
 *
 * Fixture: tests/Fixture/PhpFileFinderProject/
 *   - Top.php        : top-level php file
 *   - Upper.PHP       : uppercase extension, must still be matched
 *   - notes.txt       : non-php file, must be excluded
 *   - sub/Nested.php  : nested php file
 */
final class PhpFileFinderTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        $path = realpath(__DIR__ . '/Fixture/PhpFileFinderProject');
        self::assertNotFalse($path, 'PhpFileFinderProject fixture not found');
        self::$root = $path;
    }

    public function testFindsPhpFilesRecursivelyAndExcludesOtherExtensions(): void
    {
        $files = PhpFileFinder::findPhpFiles(self::$root);

        sort($files);

        self::assertSame(
            [
                self::$root . '/Top.php',
                self::$root . '/Upper.PHP',
                self::$root . '/sub/Nested.php',
            ],
            $files
        );
    }

    public function testExtensionMatchIsCaseInsensitive(): void
    {
        $files = PhpFileFinder::findPhpFiles(self::$root);

        self::assertContains(self::$root . '/Upper.PHP', $files);
    }

    public function testNonPhpFilesAreExcluded(): void
    {
        $files = PhpFileFinder::findPhpFiles(self::$root);

        foreach ($files as $file) {
            self::assertStringNotContainsString('notes.txt', $file);
        }
    }
}
