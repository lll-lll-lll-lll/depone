<?php

declare(strict_types=1);

namespace Depone\Tests;

use PHPUnit\Framework\TestCase;
use Depone\Internal\Resolver\ComposerLoaderVerifier;

/**
 * Unit tests for ComposerLoaderVerifier.
 *
 * Fixture: tests/Fixture/VerifyProject/
 *   - App\Ok    : dumped psr4 map agrees with composer.json -> verified
 *   - App\Stale : dumped classmap pins it to legacy/Stale.php -> mismatch
 *   - Lib\Thing : dumped psr4 map omits the Lib\ prefix entirely -> unknown
 */
final class ComposerLoaderVerifierTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        $path = realpath(__DIR__ . '/Fixture/VerifyProject');
        self::assertNotFalse($path, 'VerifyProject fixture not found');
        self::$root = $path;
    }

    public function testIsAvailableTrueWhenDumpedMapsExist(): void
    {
        self::assertTrue(ComposerLoaderVerifier::isAvailable(self::$root));
    }

    public function testIsAvailableFalseWithoutVendorDirectory(): void
    {
        $path = realpath(__DIR__ . '/Fixture/CliApplicationProject');
        self::assertNotFalse($path, 'CliApplicationProject fixture not found');
        self::assertFalse(ComposerLoaderVerifier::isAvailable($path));
    }

    public function testVerifyClassReportsVerifiedWhenDumpAgrees(): void
    {
        $verifier = new ComposerLoaderVerifier(self::$root);

        self::assertSame(
            ['status' => 'verified', 'loaderPath' => null],
            $verifier->verifyClass('App\Ok', self::$root . '/src/Ok.php')
        );
    }

    public function testVerifyClassReportsMismatchWhenDumpDisagrees(): void
    {
        $verifier = new ComposerLoaderVerifier(self::$root);

        $result = $verifier->verifyClass('App\Stale', self::$root . '/src/Stale.php');
        self::assertSame('mismatch', $result['status']);
        self::assertSame(self::$root . '/legacy/Stale.php', $result['loaderPath']);
    }

    public function testVerifyClassReportsUnknownWhenAbsentFromDump(): void
    {
        $verifier = new ComposerLoaderVerifier(self::$root);

        self::assertSame(
            ['status' => 'unknown', 'loaderPath' => null],
            $verifier->verifyClass('Lib\Thing', self::$root . '/lib/Thing.php')
        );
    }

    public function testVerifyEagerTargetTrueForDumpedFilesEntry(): void
    {
        $verifier = new ComposerLoaderVerifier(self::$root);

        self::assertTrue($verifier->verifyEagerTarget(self::$root . '/src/eager.php'));
    }

    public function testVerifyEagerTargetFalseForOtherFiles(): void
    {
        $verifier = new ComposerLoaderVerifier(self::$root);

        self::assertFalse($verifier->verifyEagerTarget(self::$root . '/src/Ok.php'));
    }
}
