<?php

declare(strict_types=1);

namespace Depone\Internal\Resolver;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Recursively discovers `.php` files under a directory.
 *
 * Shared by AutoloadCandidateCollector (classmap/psr-4/psr-0 directory entries)
 * and AutoloadResolver (classmap directory entries).
 *
 * @internal
 */
final class PhpFileFinder
{
    /**
     * Recursively finds `.php` files under the given directory (case-insensitive
     * extension match, `.` and `..` skipped). Pathnames are returned exactly as
     * produced by the iterator (no normalization) so callers can apply whatever
     * path handling they already rely on.
     *
     * @return list<string>
     * @throws \UnexpectedValueException When the directory cannot be opened;
     *                                   callers decide whether to raise or skip
     */
    public static function findPhpFiles(string $dir): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $info) {
            /** @var SplFileInfo $info */
            if ($info->isFile() && strtolower($info->getExtension()) === 'php') {
                $files[] = $info->getPathname();
            }
        }

        return $files;
    }
}
