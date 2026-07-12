<?php

declare(strict_types=1);

namespace Depone\Internal\Resolver;

use Composer\Autoload\ClassLoader;
use Composer\ClassMapGenerator\ClassMapGenerator;
use Symfony\Component\Finder\Finder;

/**
 * Resolves class names to file paths from Composer autoload settings.
 *
 * When Composer has dumped its autoloader (`vendor/composer/autoload_*.php`
 * present), those generated maps are used: they already merge the root project
 * with every installed dependency exactly as Composer resolves them at runtime,
 * so a class provided by a dependency resolves too. Without a dumped autoloader
 * (autoload never generated, e.g. a project that has not run `composer install`)
 * the resolver falls back to parsing the root `composer.json` on its own.
 *
 * Either way the maps are fed into Composer's own {@see ClassLoader}, so the
 * lookup — classmap first, then PSR-4, then PSR-0, longest prefix wins,
 * PSR-0 underscore handling — is Composer's runtime implementation, not a
 * re-derivation of it.
 *
 * @internal
 */
final class AutoloadResolver
{
    private ClassLoader $loader;

    private string $repoRoot;

    public function __construct(string $repoRoot)
    {
        $this->repoRoot = rtrim($repoRoot, '/');
        $this->loader = new ClassLoader();
        // Prefer Composer's dumped autoloader (root + dependencies, merged as
        // Composer sees them at runtime); fall back to reading composer.json
        // directly when autoload has not been generated.
        if (!$this->loadGeneratedAutoload()) {
            $this->loadComposerAutoload();
        }
    }

    /**
     * Resolves a class name to a file path.
     *
     * @param string $className Fully qualified class name
     * @return string|null Absolute file path, or null when it cannot be resolved
     */
    public function resolve(string $className): ?string
    {
        $file = $this->loader->findFile(ltrim($className, '\\'));

        return $file === false ? null : $file;
    }

    /**
     * Loads Composer's dumped autoload maps (`vendor/composer/autoload_*.php`).
     *
     * These generated files already merge the root project and every installed
     * dependency, and hold pre-computed absolute paths, so they are the most
     * faithful picture of what actually loads at runtime — including
     * dependency-provided classes and any `exclude-from-classmap` Composer
     * applied when dumping.
     *
     * @return bool True when a dumped autoloader was found and loaded; false
     *              when none exists (caller should fall back to composer.json).
     */
    private function loadGeneratedAutoload(): bool
    {
        $dir = $this->repoRoot . '/vendor/composer';
        $psr4File = $dir . '/autoload_psr4.php';

        // Composer always generates the PSR-4 map alongside the others. Its
        // absence means autoload has not been dumped: signal a fallback.
        if (!is_file($psr4File)) {
            return false;
        }

        foreach ($this->requireMap($psr4File) as $prefix => $dirs) {
            if (is_string($prefix)) {
                $this->addPsr4($prefix, $this->stringPaths($dirs));
            }
        }

        $psr0File = $dir . '/autoload_namespaces.php';
        if (is_file($psr0File)) {
            foreach ($this->requireMap($psr0File) as $prefix => $dirs) {
                if (is_string($prefix)) {
                    $this->loader->add($prefix, $this->stringPaths($dirs));
                }
            }
        }

        $classmapFile = $dir . '/autoload_classmap.php';
        if (is_file($classmapFile)) {
            $classmap = [];
            foreach ($this->requireMap($classmapFile) as $class => $file) {
                if (is_string($class) && is_string($file)) {
                    $classmap[$class] = $file;
                }
            }
            $this->loader->addClassMap($classmap);
        }

        return true;
    }

    /**
     * Includes a Composer-generated autoload map in an isolated scope — so its
     * `$vendorDir`/`$baseDir` locals never leak — and always returns an array.
     *
     * @return array<mixed>
     */
    private function requireMap(string $file): array
    {
        $map = (static fn (): mixed => require $file)();

        return is_array($map) ? $map : [];
    }

    /**
     * Loads autoload settings from composer.json.
     */
    private function loadComposerAutoload(): void
    {
        $composerPath = $this->repoRoot . '/composer.json';
        if (!file_exists($composerPath)) {
            return;
        }

        $json = file_get_contents($composerPath);
        if ($json === false) {
            return;
        }

        $composer = json_decode($json, true);
        if (!is_array($composer)) {
            return;
        }

        // Classes in classmap directories/files are discovered with Composer's
        // own generator, so what lands in the map (and which file wins a
        // duplicate-class tie: the first occurrence, over a deterministic
        // scan order) matches what `composer dump-autoload` would produce.
        $generator = new ClassMapGenerator();

        // Load both autoload and autoload-dev sections.
        foreach (['autoload', 'autoload-dev'] as $key) {
            if (!isset($composer[$key]) || !is_array($composer[$key])) {
                continue;
            }

            $autoload = $composer[$key];

            // PSR-4
            if (isset($autoload['psr-4']) && is_array($autoload['psr-4'])) {
                foreach ($autoload['psr-4'] as $prefix => $paths) {
                    if (is_string($prefix)) {
                        $this->addPsr4($prefix, $this->rootPaths($paths));
                    }
                }
            }

            // PSR-0
            if (isset($autoload['psr-0']) && is_array($autoload['psr-0'])) {
                foreach ($autoload['psr-0'] as $prefix => $paths) {
                    if (is_string($prefix)) {
                        $this->loader->add($prefix, $this->rootPaths($paths));
                    }
                }
            }

            // classmap: scan the listed files and directories for declared classes.
            if (isset($autoload['classmap']) && is_array($autoload['classmap'])) {
                foreach ($autoload['classmap'] as $path) {
                    if (is_string($path)) {
                        $this->scanClassmapPath($generator, $this->repoRoot . '/' . $path);
                    }
                }
            }
        }

        $this->loader->addClassMap($generator->getClassMap()->getMap());
    }

    /**
     * Scans one `classmap` entry into the generator. Directories are handed
     * over as an explicitly sorted file list: the generator keeps the first
     * occurrence of a duplicate class (as Composer does), and the sort keeps
     * that tie-break deterministic instead of raw-filesystem-order dependent.
     */
    private function scanClassmapPath(ClassMapGenerator $generator, string $path): void
    {
        if (is_dir($path)) {
            $files = Finder::create()
                ->files()
                ->followLinks()
                ->name('/\.(?:php|inc|hh)$/')
                ->in($path)
                ->sortByName();
        } elseif (is_file($path)) {
            $files = $path;
        } else {
            return;
        }

        try {
            $generator->scanPaths($files);
        } catch (\RuntimeException) {
            // An unreadable file aborts the scan of this entry; the remaining
            // classmap entries still load, mirroring the old tolerant scanner.
        }
    }

    /**
     * Registers a PSR-4 rule. Composer refuses to dump a non-empty prefix that
     * does not end with a namespace separator (its ClassLoader rejects them),
     * so such a rule can never match at runtime and is skipped here too.
     *
     * @param list<string> $dirs
     */
    private function addPsr4(string $prefix, array $dirs): void
    {
        if ($prefix !== '' && !str_ends_with($prefix, '\\')) {
            return;
        }
        $this->loader->addPsr4($prefix, $dirs);
    }

    /**
     * Filters a dumped-map value down to its string paths.
     *
     * @return list<string>
     */
    private function stringPaths(mixed $dirs): array
    {
        $paths = [];
        foreach ((array) $dirs as $path) {
            if (is_string($path)) {
                $paths[] = rtrim($path, '/');
            }
        }

        return $paths;
    }

    /**
     * Converts a composer.json path value (string or list of strings) into
     * absolute directories under the repository root.
     *
     * @return list<string>
     */
    private function rootPaths(mixed $paths): array
    {
        $absolute = [];
        foreach (is_array($paths) ? $paths : [$paths] as $path) {
            if (is_string($path)) {
                $absolute[] = $this->repoRoot . '/' . rtrim($path, '/');
            }
        }

        return $absolute;
    }
}
