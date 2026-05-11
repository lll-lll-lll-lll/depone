<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Tests;

use PHPUnit\Framework\TestCase;
use RedundantRequireOnce\Cli\CliApplication;

/**
 * Integration tests for CliApplication.
 *
 * stdout/stderr are replaced with in-memory streams so exit codes and output
 * content can be asserted.
 * Fixture: tests/Fixture/CliApplicationProject/
 *   - public/index.php            : redundant (src/Bar.php) + non-autoload (lib/Util.php)
 *   - public/index-with-const.php : require_once using SITE_ROOT (--define required)
 *   - src/Bar.php                 : PSR-4 autoload target
 *   - lib/Util.php                : not registered in autoload
 */
final class CliApplicationTest extends TestCase
{
    private static string $fixtureRoot;

    public static function setUpBeforeClass(): void
    {
        $path = realpath(__DIR__ . '/Fixture/CliApplicationProject');
        self::assertNotFalse($path, 'CliApplicationProject fixture not found');
        self::$fixtureRoot = $path;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Runs CliApplication and returns stdout, stderr, and the exit code.
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runApp(string ...$args): array
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        self::assertNotFalse($stdout);
        self::assertNotFalse($stderr);

        $exitCode = (new CliApplication($stdout, $stderr, self::$fixtureRoot))(['bin', ...$args]);

        rewind($stdout);
        rewind($stderr);
        $result = [
            'exitCode' => $exitCode,
            'stdout'   => stream_get_contents($stdout),
            'stderr'   => stream_get_contents($stderr),
        ];
        fclose($stdout);
        fclose($stderr);

        return $result;
    }

    // -------------------------------------------------------------------------
    // --help / -h
    // -------------------------------------------------------------------------

    public function testHelpLongFlag(): void
    {
        $r = $this->runApp('--help');
        self::assertSame(0, $r['exitCode']);
        self::assertStringContainsString('Usage:', $r['stdout']);
        self::assertSame('', $r['stderr']);
    }

    public function testHelpShortFlag(): void
    {
        $r = $this->runApp('-h');
        self::assertSame(0, $r['exitCode']);
        self::assertStringContainsString('Usage:', $r['stdout']);
    }

    // -------------------------------------------------------------------------
    // Default text output
    // -------------------------------------------------------------------------

    public function testDefaultTextOutputExitsZero(): void
    {
        $r = $this->runApp();
        self::assertSame(0, $r['exitCode']);
        self::assertSame('', $r['stderr']);
    }

    public function testDefaultTextOutputContainsSummaryKeys(): void
    {
        $r = $this->runApp();
        self::assertStringContainsString('redundant_require_once=', $r['stdout']);
        self::assertStringContainsString('unresolved_include_require=', $r['stdout']);
        // Without --include-non-autoload, the non_autoload section should be omitted.
        self::assertStringNotContainsString('non_autoload_require_once=', $r['stdout']);
    }

    public function testRedundantRequireOnceDetectedInTextOutput(): void
    {
        $r = $this->runApp();
        // The require_once to src/Bar.php in public/index.php should be reported as redundant.
        self::assertStringContainsString('redundant_require_once=1', $r['stdout']);
        self::assertStringContainsString('src/Bar.php', $r['stdout']);
    }

    // -------------------------------------------------------------------------
    // --include-non-autoload
    // -------------------------------------------------------------------------

    public function testIncludeNonAutoloadShowsSection(): void
    {
        $r = $this->runApp('--include-non-autoload');
        self::assertSame(0, $r['exitCode']);
        self::assertStringContainsString('non_autoload_require_once=', $r['stdout']);
    }

    public function testIncludeNonAutoloadDetectsLibUtil(): void
    {
        $r = $this->runApp('--include-non-autoload');
        // lib/Util.php is not registered in autoload, so it should be reported separately.
        self::assertStringContainsString('non_autoload_require_once=1', $r['stdout']);
        self::assertStringContainsString('lib/Util.php', $r['stdout']);
    }

    // -------------------------------------------------------------------------
    // --json
    // -------------------------------------------------------------------------

    public function testJsonOutputExitsZero(): void
    {
        $r = $this->runApp('--json');
        self::assertSame(0, $r['exitCode']);
        self::assertSame('', $r['stderr']);
    }

    public function testJsonOutputIsValidJson(): void
    {
        $r = $this->runApp('--json');
        $data = json_decode($r['stdout'], true);
        self::assertIsArray($data);
    }

    public function testJsonOutputHasRequiredKeys(): void
    {
        $r  = $this->runApp('--json');
        $data = json_decode($r['stdout'], true);
        self::assertArrayHasKey('redundant', $data);
        self::assertArrayHasKey('unresolved', $data);
        self::assertArrayHasKey('edges', $data);
        // Without --include-non-autoload, the nonAutoloadRequireOnce key should be absent.
        self::assertArrayNotHasKey('nonAutoloadRequireOnce', $data);
    }

    public function testJsonOutputRedundantEntry(): void
    {
        $r    = $this->runApp('--json');
        $data = json_decode($r['stdout'], true);
        self::assertCount(1, $data['redundant']);
        self::assertSame('public/index.php', $data['redundant'][0]['file']);
        self::assertSame('src/Bar.php', $data['redundant'][0]['target']);
    }

    // -------------------------------------------------------------------------
    // --define
    // -------------------------------------------------------------------------

    public function testWithoutDefineConstantIsUnresolved(): void
    {
        // With no SITE_ROOT definition, the include in index-with-const.php stays unresolved.
        $r    = $this->runApp('--json');
        $data = json_decode($r['stdout'], true);
        $unresolvedFiles = array_column($data['unresolved'], 'file');
        self::assertContains('public/index-with-const.php', $unresolvedFiles);
    }

    public function testDefineResolvesConstant(): void
    {
        // Defining SITE_ROOT makes the include resolvable and therefore redundant.
        $siteRoot = self::$fixtureRoot . '/';
        $r    = $this->runApp('--define', "SITE_ROOT={$siteRoot}", '--json');
        $data = json_decode($r['stdout'], true);

        // unresolved should drop to zero
        self::assertCount(0, $data['unresolved']);

        // redundant should contain both index.php and index-with-const.php
        self::assertCount(2, $data['redundant']);
        $targets = array_column($data['redundant'], 'target');
        self::assertContains('src/Bar.php', $targets);
    }

    // -------------------------------------------------------------------------
    // --trace / --deps (JSON)
    // -------------------------------------------------------------------------

    public function testTraceJsonOutput(): void
    {
        $r = $this->runApp('--trace', 'src/Bar.php', '--json');
        self::assertSame(0, $r['exitCode']);
        $data = json_decode($r['stdout'], true);
        self::assertArrayHasKey('trace', $data);
        self::assertArrayNotHasKey('redundant', $data);
        self::assertSame('src/Bar.php', $data['trace']['target']);
        self::assertContains('public/index.php', $data['trace']['directCallers']);
    }

    public function testDepsJsonOutput(): void
    {
        $r = $this->runApp('--deps', 'public/index.php', '--json');
        self::assertSame(0, $r['exitCode']);
        $data = json_decode($r['stdout'], true);
        self::assertArrayHasKey('deps', $data);
        self::assertArrayNotHasKey('redundant', $data);
        self::assertSame('public/index.php', $data['deps']['target']);
        self::assertContains('src/Bar.php', $data['deps']['directDependencies']);
        self::assertContains('lib/Util.php', $data['deps']['directDependencies']);
    }

    public function testTraceAndDepsJsonCombined(): void
    {
        $r = $this->runApp('--trace', 'src/Bar.php', '--deps', 'public/index.php', '--json');
        self::assertSame(0, $r['exitCode']);
        $data = json_decode($r['stdout'], true);
        self::assertArrayHasKey('trace', $data);
        self::assertArrayHasKey('deps', $data);
    }

    // -------------------------------------------------------------------------
    // --trace / --deps (text)
    // -------------------------------------------------------------------------

    public function testTraceTextOutput(): void
    {
        $r = $this->runApp('--trace', 'src/Bar.php');
        self::assertSame(0, $r['exitCode']);
        self::assertStringContainsString('trace_target=src/Bar.php', $r['stdout']);
        self::assertStringContainsString('direct_callers=', $r['stdout']);
    }

    public function testDepsTextOutput(): void
    {
        $r = $this->runApp('--deps', 'public/index.php');
        self::assertSame(0, $r['exitCode']);
        self::assertStringContainsString('deps_target=public/index.php', $r['stdout']);
        self::assertStringContainsString('direct_dependencies=', $r['stdout']);
    }

    // -------------------------------------------------------------------------
    // Error cases
    // -------------------------------------------------------------------------

    public function testUnknownOptionExitsOne(): void
    {
        $r = $this->runApp('--no-such-option');
        self::assertSame(1, $r['exitCode']);
        self::assertStringContainsString('Unknown option', $r['stderr']);
        self::assertSame('', $r['stdout']);
    }

    public function testAnalyzerExceptionExitsOne(): void
    {
        // Using a repoRoot without composer.json should surface an error.
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        self::assertNotFalse($stdout);
        self::assertNotFalse($stderr);

        $noComposerDir = __DIR__ . '/Fixture'; // directory without composer.json
        $exitCode = (new CliApplication($stdout, $stderr, $noComposerDir))(['bin']);

        rewind($stderr);
        $stderrContent = stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);

        self::assertSame(1, $exitCode);
        self::assertNotSame('', $stderrContent);
    }
}
