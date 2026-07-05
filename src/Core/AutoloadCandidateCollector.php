<?php

declare(strict_types=1);

namespace Depone\Internal\Core;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Depone\Internal\Exception\AnalyzerException;
use Depone\Internal\Tokenizer\PathHelper;
use SplFileInfo;

/**
 * Collects the candidate autoload file set (psr-4/psr-0/classmap entries) and the
 * eagerly loaded file set (`autoload.files`/`autoload-dev.files`) declared in
 * composer.json.
 *
 * Shared by Analyzer (which further filters candidates through a resolution
 * round-trip check) and AutoloadDoctor (which diagnoses unreachable candidates).
 *
 * @phpstan-type CandidateSet array{candidates: array<string, true>, files: array<string, true>}
 *
 * @internal
 */
final class AutoloadCandidateCollector
{
    private string $repoRoot;

    public function __construct(string $repoRoot)
    {
        $this->repoRoot = PathHelper::normalize($repoRoot);
    }

    /**
     * Reads composer.json and collects candidate and eager file sets.
     *
     * @return CandidateSet
     * @throws AnalyzerException
     */
    public function collect(): array
    {
        $composerPath = $this->repoRoot . '/composer.json';
        if (!is_file($composerPath)) {
            throw new AnalyzerException("Failed to read composer.json");
        }
        $json = file_get_contents($composerPath);
        if (!is_string($json)) {
            throw new AnalyzerException("Failed to read composer.json");
        }
        $composer = json_decode($json, true);
        if (!is_array($composer)) {
            throw new AnalyzerException("Failed to decode composer.json");
        }

        $files = [];
        $candidateFiles = [];

        foreach (['autoload', 'autoload-dev'] as $sectionName) {
            $autoload = $composer[$sectionName] ?? [];
            if (!is_array($autoload)) {
                continue;
            }

            // files: individual files
            $this->collectFromFiles($autoload['files'] ?? [], $files);

            // classmap: directories or individual files
            $this->collectFromClassmap($autoload['classmap'] ?? [], $candidateFiles);

            // psr-4: namespace => directory mapping
            $this->collectFromPsr4($autoload['psr-4'] ?? [], $candidateFiles);

            // psr-0: namespace => directory mapping (legacy)
            $this->collectFromPsr4($autoload['psr-0'] ?? [], $candidateFiles);
        }

        return [
            'candidates' => $candidateFiles,
            'files' => $files,
        ];
    }

    /**
     * Collects files from classmap entries.
     *
     * @param array<string, true> $files Destination array, passed by reference
     */
    private function collectFromClassmap(mixed $entries, array &$files): void
    {
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $absolute = PathHelper::normalize($this->repoRoot . '/' . ltrim($entry, '/'));
            $this->collectPhpFilesFromPath($absolute, $files);
        }
    }

    /**
     * Collects files from `files` entries.
     *
     * @param array<string, true> $files Destination array, passed by reference
     */
    private function collectFromFiles(mixed $entries, array &$files): void
    {
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $absolute = PathHelper::normalize($this->repoRoot . '/' . ltrim($entry, '/'));
            if (is_file($absolute)) {
                $files[$absolute] = true;
            }
        }
    }

    /**
     * Collects files from psr-4/psr-0 entries.
     *
     * @param array<string, true> $files Destination array, passed by reference
     */
    private function collectFromPsr4(mixed $entries, array &$files): void
    {
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $paths) {
            // Paths may be declared as a string or an array.
            $pathList = is_array($paths) ? $paths : [$paths];
            foreach ($pathList as $path) {
                if (!is_string($path)) {
                    continue;
                }
                $absolute = PathHelper::normalize($this->repoRoot . '/' . ltrim($path, '/'));
                $this->collectPhpFilesFromPath($absolute, $files);
            }
        }
    }

    /**
     * Collects PHP files from the given path, whether it is a file or directory.
     *
     * @param array<string, true> $files Destination array, passed by reference
     */
    private function collectPhpFilesFromPath(string $absolute, array &$files): void
    {
        if (is_dir($absolute)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $info) {
                /** @var SplFileInfo $info */
                if ($info->isFile() && strtolower($info->getExtension()) === 'php') {
                    $files[PathHelper::normalize((string)$info->getPathname())] = true;
                }
            }
            return;
        }

        if (is_file($absolute)) {
            $files[$absolute] = true;
        }
    }
}
