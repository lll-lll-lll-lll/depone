<?php

declare(strict_types=1);

namespace Depone\Internal\Core;

use Depone\Internal\Exception\AnalyzerException;
use Depone\Internal\Resolver\AutoloadResolver;
use Depone\Internal\Tokenizer\DeclaredClassExtractor;
use Depone\Internal\Tokenizer\PathHelper;

/**
 * Diagnoses files and classes that the Composer autoloader can never reach:
 * classes shadowed by another autoload winner, classes whose namespace maps to
 * a path that does not exist, classes matching no autoload rule at all, and
 * candidate files that declare no types.
 *
 * @phpstan-type DoctorFinding array{severity: 'error'|'warning'|'info', reason: string, file: string, detail: string}
 * @phpstan-type DoctorResult array{errors: list<DoctorFinding>, warnings: list<DoctorFinding>, info: list<DoctorFinding>}
 *
 * @internal
 */
final class AutoloadDoctor
{
    private string $repoRoot;

    public function __construct(string $repoRoot)
    {
        $this->repoRoot = PathHelper::normalize($repoRoot);
    }

    /**
     * Runs the diagnosis and returns findings grouped by severity.
     *
     * @return DoctorResult
     * @throws AnalyzerException
     */
    public function run(): array
    {
        $collector = new AutoloadCandidateCollector($this->repoRoot);
        $collected = $collector->collect();
        $candidateFiles = $collected['candidates'];
        $eagerFiles = $collected['files'];

        $resolver = new AutoloadResolver($this->repoRoot);
        $classExtractor = new DeclaredClassExtractor();

        $findings = [];

        foreach (array_keys($candidateFiles) as $filePath) {
            $normalizedFile = PathHelper::normalize($filePath);
            $relativeFile = PathHelper::toRelative($normalizedFile, $this->repoRoot);

            $content = file_get_contents($filePath);
            $classNames = is_string($content) ? $classExtractor->extract($content) : [];

            if ($classNames === []) {
                if (!isset($eagerFiles[$normalizedFile])) {
                    $findings[] = [
                        'severity' => 'info',
                        'reason' => 'no_declarations',
                        'file' => $relativeFile,
                        'detail' => 'no type declarations',
                    ];
                }
                continue;
            }

            foreach ($classNames as $className) {
                $finding = $this->classifyClass($className, $normalizedFile, $relativeFile, $resolver);
                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
        }

        return $this->groupFindings($findings);
    }

    /**
     * Classifies a single declared class against the resolver, returning a
     * finding when the class is unreachable, or null when it round-trips.
     *
     * @return DoctorFinding|null
     */
    private function classifyClass(
        string $className,
        string $normalizedFile,
        string $relativeFile,
        AutoloadResolver $resolver
    ): ?array {
        $verbose = $resolver->resolveVerbose($className);
        $resolved = $verbose['resolved'] !== null ? PathHelper::normalize($verbose['resolved']) : null;

        if ($resolved === $normalizedFile) {
            return null;
        }

        if ($resolved !== null) {
            $winner = PathHelper::toRelative($resolved, $this->repoRoot);

            return [
                'severity' => 'error',
                'reason' => 'resolved_elsewhere',
                'file' => $relativeFile,
                'detail' => "{$className} is shadowed by {$winner}",
            ];
        }

        if ($verbose['prefix'] !== null) {
            $expectedPath = $verbose['expectedPath'];
            assert($expectedPath !== null);
            $expectedRelative = PathHelper::toRelative(PathHelper::normalize($expectedPath), $this->repoRoot);

            return [
                'severity' => 'error',
                'reason' => 'expected_path_missing',
                'file' => $relativeFile,
                'detail' => "{$className} would load from {$expectedRelative} — not found",
            ];
        }

        return [
            'severity' => 'warning',
            'reason' => 'no_matching_rule',
            'file' => $relativeFile,
            'detail' => "{$className} matches no autoload rule",
        ];
    }

    /**
     * Sorts findings deterministically and groups them by severity.
     *
     * @param list<DoctorFinding> $findings
     * @return DoctorResult
     */
    private function groupFindings(array $findings): array
    {
        usort($findings, static function (array $a, array $b): int {
            return [$a['file'], $a['detail']] <=> [$b['file'], $b['detail']];
        });

        $errors = [];
        $warnings = [];
        $info = [];
        foreach ($findings as $finding) {
            match ($finding['severity']) {
                'error' => $errors[] = $finding,
                'warning' => $warnings[] = $finding,
                'info' => $info[] = $finding,
            };
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'info' => $info];
    }
}
