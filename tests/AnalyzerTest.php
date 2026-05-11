<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Tests;

use PHPUnit\Framework\TestCase;
use RedundantRequireOnce\Core\Analyzer;
use RedundantRequireOnce\Core\DependencyGraph;

final class AnalyzerTest extends TestCase
{
    public function testRunDetectsRedundantRequireOnce(): void
    {
        $projectRoot = $this->getFixturePath('SampleProject');

        $result = (new Analyzer($projectRoot))->run();

        self::assertSame(
            [
                [
                    'file' => 'public/index.php',
                    'line' => 5,
                    'target' => 'src/Bar.php',
                ],
            ],
            $result['redundant']
        );
        self::assertSame([], $result['nonAutoloadRequireOnce']);
        self::assertSame([], $result['unresolved']);
    }

    public function testReverseTraceIncludesRequireOnceAndAutoloadEdges(): void
    {
        $projectRoot = $this->getFixturePath('SampleProject');

        $result = (new Analyzer($projectRoot))
            ->enableAutoloadEdges()
            ->run();

        $graph = new DependencyGraph($result['edges'], $projectRoot);
        $trace = $graph->buildReverseTrace('src/Foo.php', 20, 25);

        self::assertSame('src/Foo.php', $trace['target']);
        self::assertSame(['src/Bar.php'], $trace['directCallers']);
        self::assertSame(['public/index.php'], $trace['entrypoints']);
        self::assertSame(
            [
                [
                    ['node' => 'public/index.php', 'type' => null],
                    ['node' => 'src/Bar.php', 'type' => 'require_once'],
                    ['node' => 'src/Foo.php', 'type' => 'autoload'],
                ],
            ],
            $trace['paths']
        );
        self::assertFalse($trace['truncated']);
    }

    public function testAnalyzeFilePreservesUrlRequireOnceTargets(): void
    {
        $analyzer = new Analyzer('/project');

        $result = $analyzer->analyzeFile(
            <<<'PHP'
<?php
require_once 'https://example.com/bootstrap.php';
PHP,
            '/project/public/index.php',
            'public/index.php',
            [],
            []
        );

        self::assertSame(
            [
                [
                    'file' => 'public/index.php',
                    'line' => 2,
                    'target' => 'https://example.com/bootstrap.php',
                ],
            ],
            $result['nonAutoloadRequireOnce']
        );
        self::assertSame([], $result['redundant']);
        self::assertSame([], $result['unresolved']);
        self::assertSame(
            [
                [
                    'from' => 'public/index.php',
                    'line' => 2,
                    'type' => 'require_once',
                    'to' => 'https://example.com/bootstrap.php',
                ],
            ],
            $result['edges']
        );
    }

    public function testRunDoesNotTreatHelperPhpUnderPsr4DirectoryAsAutoloaded(): void
    {
        $projectRoot = $this->getFixturePath('AnalyzerAutoloadCoverageProject');

        $result = (new Analyzer($projectRoot))->run();

        self::assertSame(
            [
                [
                    'file' => 'tests/bootstrap.php',
                    'line' => 5,
                    'target' => 'dev-tests/TestSupport.php',
                ],
            ],
            $result['redundant']
        );
        self::assertSame(
            [
                [
                    'file' => 'public/index.php',
                    'line' => 5,
                    'target' => 'src/helpers.php',
                ],
            ],
            $result['nonAutoloadRequireOnce']
        );
        self::assertSame([], $result['unresolved']);
    }

    private function getFixturePath(string $name): string
    {
        $path = realpath(__DIR__ . '/Fixture/' . $name);
        self::assertNotFalse($path);

        return $path;
    }
}
