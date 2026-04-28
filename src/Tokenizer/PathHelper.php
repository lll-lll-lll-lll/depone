<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Tokenizer;

final class PathHelper
{
    /**
     * Normalizes a path by converting backslashes to slashes and resolving `.` and `..`.
     *
     * @param string $path Input path
     * @return string Normalized path
     */
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $isAbs = ($path !== '' && $path[0] === '/');
        $segments = explode('/', $path);
        $stack = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                // Pop for absolute paths, or when a normal segment exists on the stack.
                // Preserve `..` for relative paths when there is nothing left to pop.
                if ($isAbs) {
                    if ($stack !== []) {
                        array_pop($stack);
                    }
                } elseif ($stack !== [] && $stack[count($stack) - 1] !== '..') {
                    array_pop($stack);
                } else {
                    $stack[] = '..';
                }
                continue;
            }
            $stack[] = $segment;
        }

        $normalized = implode('/', $stack);

        return $isAbs ? '/' . $normalized : $normalized;
    }

    /**
     * Converts an absolute path to a path relative to the repository root.
     *
     * @param string $absolute Absolute path
     * @param string $repoRoot Absolute repository root path
     * @return string Relative path, or the original absolute path when it is outside the repository
     */
    public static function toRelative(string $absolute, string $repoRoot): string
    {
        $absolute = self::normalize($absolute);
        $prefix = self::normalize($repoRoot) . '/';

        if (str_starts_with($absolute, $prefix)) {
            return substr($absolute, strlen($prefix));
        }

        return $absolute;
    }

    /**
     * Resolves a require/include path to an absolute path.
     *
     * - URL schemes (for example http://) are returned unchanged
     * - absolute paths are normalized and returned as-is
     * - relative paths are resolved from the file being analyzed
     *
     * @param string $rawValue Evaluated path string
     * @param string $contextFile Absolute path of the file being analyzed
     * @return string Resolved absolute path
     */
    public static function resolveRequiredPath(string $rawValue, string $contextFile): string
    {
        if (self::isUrl($rawValue)) {
            return $rawValue;
        }
        if ($rawValue !== '' && $rawValue[0] === '/') {
            return self::normalize($rawValue);
        }

        return self::normalize(dirname($contextFile) . '/' . $rawValue);
    }

    /**
     * Returns whether the given string is a URL.
     *
     * @param string $path String to test
     * @return bool True when the string is a URL
     */
    public static function isUrl(string $path): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\\/\\//', $path) === 1;
    }
}
