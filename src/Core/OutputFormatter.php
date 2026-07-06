<?php

declare(strict_types=1);

namespace Depone\Internal\Core;

/**
 * Formats analysis output.
 *
 * @phpstan-import-type AnalysisResult from \Depone\Internal\Core\Analyzer
 * @phpstan-import-type TraceResult from \Depone\Internal\Core\DependencyGraph
 * @phpstan-import-type TracePath from \Depone\Internal\Core\DependencyGraph
 *
 * @internal
 */
final class OutputFormatter
{
    /**
     * Formats a text summary of the analysis result.
     *
     * @param AnalysisResult $result
     */
    public function formatSummary(array $result): string
    {
        $output = '';
        foreach (Analyzer::ACTIONABLE_CATEGORIES as $category) {
            $output .= "{$category}_require_once=" . count($result[$category]) . PHP_EOL;
            foreach ($result[$category] as $row) {
                // The text output is a frozen contract: redundant rows print
                // unindented with no detail, classified rows indented with one.
                $output .= isset($row['detail'])
                    ? "  {$row['file']}:{$row['line']} => {$row['target']}  ({$row['detail']})" . PHP_EOL
                    : "{$row['file']}:{$row['line']} => {$row['target']}" . PHP_EOL;
            }
            $output .= PHP_EOL;
        }
        $output .= "unresolved_include_require=" . count($result['unresolved']) . PHP_EOL;
        foreach ($result['unresolved'] as $row) {
            $output .= "  {$row['file']}:{$row['line']} [{$row['reason']}] {$row['expr']}" . PHP_EOL;
        }

        return $output;
    }

    /**
     * Formats the analysis result as JSON: depone's machine-readable
     * contract. Every section is built explicitly, key by key, rather than
     * `json_encode`d straight from the internal result array, so the schema
     * is decoupled from internal array shapes and can evolve independently.
     * `schema_version` is bumped whenever the shape changes in a
     * backward-incompatible way.
     *
     * @param AnalysisResult $result
     */
    public function formatJson(array $result): string
    {
        $document = [
            'schema_version' => 1,
            'summary' => [
                'includes_total' => count($result['edges']) + count($result['unresolved']),
                'resolved' => count($result['edges']),
                'unresolved' => count($result['unresolved']),
                'require_once' => [
                    'redundant' => count($result['redundant']),
                    'fixable' => count($result['fixable']),
                    'conflicting' => count($result['conflicting']),
                    'needed' => count($result['needed']),
                ],
            ],
            'redundant' => array_map(
                static fn (array $row): array => [
                    'file' => $row['file'],
                    'line' => $row['line'],
                    'target' => $row['target'],
                    'proof' => [
                        'eager' => $row['proof']['eager'],
                        'pure_declaration' => $row['proof']['pure_declaration'],
                        'classes' => array_map(
                            static fn (array $evidence): array => [
                                'class' => $evidence['class'],
                                'via' => $evidence['via'],
                                'prefix' => $evidence['prefix'],
                                'path' => $evidence['path'],
                            ],
                            $row['proof']['classes']
                        ),
                    ],
                ],
                $result['redundant']
            ),
            'fixable' => array_map(
                static fn (array $row): array => [
                    'file' => $row['file'],
                    'line' => $row['line'],
                    'target' => $row['target'],
                    'class' => $row['class'],
                    'expected_path' => $row['expected_path'],
                    'detail' => $row['detail'],
                ],
                $result['fixable']
            ),
            'conflicting' => array_map(
                static fn (array $row): array => [
                    'file' => $row['file'],
                    'line' => $row['line'],
                    'target' => $row['target'],
                    'class' => $row['class'],
                    'loaded_from' => $row['loaded_from'],
                    'detail' => $row['detail'],
                ],
                $result['conflicting']
            ),
            'needed' => array_map(
                static fn (array $row): array => [
                    'file' => $row['file'],
                    'line' => $row['line'],
                    'target' => $row['target'],
                    'reason' => $row['reason'],
                ],
                $result['needed']
            ),
            'unresolved' => array_map(
                static fn (array $row): array => [
                    'file' => $row['file'],
                    'line' => $row['line'],
                    'type' => $row['type'],
                    'reason' => $row['reason'],
                    'expr' => $row['expr'],
                ],
                $result['unresolved']
            ),
        ];

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('failed to encode analysis result as JSON: ' . json_last_error_msg());
        }

        return $json . PHP_EOL;
    }

    /**
     * Formats reverse-trace output.
     *
     * @param TraceResult $trace
     */
    public function formatReverseTrace(array $trace): string
    {
        $output = "trace_target={$trace['target']}" . PHP_EOL;
        $output .= "direct_callers=" . count($trace['directCallers']) . PHP_EOL;
        $output .= $this->formatList($trace['directCallers']);
        $output .= "entrypoint_candidates=" . count($trace['entrypoints']) . PHP_EOL;
        $output .= $this->formatList($trace['entrypoints']);
        $output .= "trace_paths=" . count($trace['paths']) . PHP_EOL;
        $output .= $this->formatPaths($trace['paths']);
        if ($trace['truncated']) {
            $output .= "  (trace path list truncated)" . PHP_EOL;
        }

        return $output;
    }

    /**
     * @param list<string> $items
     */
    private function formatList(array $items): string
    {
        $output = '';
        foreach ($items as $item) {
            $output .= "  - {$item}" . PHP_EOL;
        }

        return $output;
    }

    /**
     * Formats a list of paths.
     *
     * @param list<TracePath> $paths
     */
    private function formatPaths(array $paths): string
    {
        $output = '';
        foreach ($paths as $index => $path) {
            $number = $index + 1;
            $output .= "  {$number}. " . $this->formatSinglePath($path) . PHP_EOL;
        }

        return $output;
    }

    /**
     * Formats a single path.
     * All edges are require/include statements (require_once/require/include_once/include),
     * so the arrow marker is always -[r]->.
     *
     * @param TracePath $path
     */
    private function formatSinglePath(array $path): string
    {
        return implode(' -[r]-> ', $path);
    }
}
