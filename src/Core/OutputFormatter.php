<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Core;

/**
 * Formats analysis output.
 */
final class OutputFormatter
{
    /**
     * Formats the analysis result as JSON.
     */
    public function outputJson(array $result): string
    {
        return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    }

    /**
     * Formats a text summary of the analysis result.
     */
    public function formatSummary(array $result): string
    {
        $output = "redundant_require_once=" . count($result['redundant']) . PHP_EOL;
        foreach ($result['redundant'] as $row) {
            $output .= "{$row['file']}:{$row['line']} => {$row['target']}" . PHP_EOL;
        }
        if (isset($result['nonAutoloadRequireOnce'])) {
            $output .= PHP_EOL;
            $output .= "non_autoload_require_once=" . count($result['nonAutoloadRequireOnce']) . PHP_EOL;
            foreach ($result['nonAutoloadRequireOnce'] as $row) {
                $output .= "{$row['file']}:{$row['line']} => {$row['target']}" . PHP_EOL;
            }
        }
        $output .= PHP_EOL;
        $output .= "unresolved_include_require=" . count($result['unresolved']) . PHP_EOL;
        foreach ($result['unresolved'] as $row) {
            $output .= "  {$row['file']}:{$row['line']} [{$row['reason']}] {$row['expr']}" . PHP_EOL;
        }

        return $output;
    }

    /**
     * Formats reverse-trace output.
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
     * Formats forward-trace output.
     */
    public function formatForwardTrace(array $deps): string
    {
        $output = "deps_target={$deps['target']}" . PHP_EOL;
        $output .= "direct_dependencies=" . count($deps['directDependencies']) . PHP_EOL;
        $output .= $this->formatList($deps['directDependencies']);
        $output .= "leaf_files=" . count($deps['leafFiles']) . PHP_EOL;
        $output .= $this->formatList($deps['leafFiles']);
        $output .= "deps_paths=" . count($deps['paths']) . PHP_EOL;
        $output .= $this->formatPaths($deps['paths']);
        if ($deps['truncated']) {
            $output .= "  (deps path list truncated)" . PHP_EOL;
        }

        return $output;
    }

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
     * The arrow marker depends on the edge type:
     * - require_once/require/include_once/include: -[r]->
     * - autoload: -[a]->
     *
     * @param array<array{node: string, type: string|null}> $path
     */
    private function formatSinglePath(array $path): string
    {
        $parts = [];
        foreach ($path as $i => $entry) {
            $node = $entry['node'];
            $type = $entry['type'];

            if ($i === 0) {
                // The first node has no incoming edge marker.
                $parts[] = $node;
            } else {
                // Pick the arrow style for the edge type.
                $arrow = $this->getArrowForType($type);
                $parts[] = $arrow . ' ' . $node;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Returns the arrow marker for the given edge type.
     */
    private function getArrowForType(?string $type): string
    {
        if ($type === 'autoload') {
            return '-[a]->';
        }

        // require_once, require, include_once, include, or null
        return '-[r]->';
    }
}
