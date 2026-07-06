<?php

declare(strict_types=1);

namespace Depone\Internal\Resolver;

use Composer\Autoload\ClassLoader;
use Depone\Internal\Exception\AnalyzerException;

/**
 * Cross-checks depone's static autoload resolution against the maps Composer
 * actually dumped for the analyzed project, under its `vendor/composer/`
 * directory -- the ground truth `vendor/autoload.php` would use at runtime.
 *
 * SAFETY: this class never requires the analyzed project's
 * `vendor/autoload.php`. That file executes every `autoload.files` entry as a
 * side effect of being loaded, which would violate depone's core guarantee of
 * never running the code it analyzes. It only ever loads the dumped map
 * files themselves -- `autoload_psr4.php`, `autoload_namespaces.php`,
 * `autoload_classmap.php`, `autoload_files.php` -- which are pure
 * `return array(...)` documents, each through a scope-isolated closure so
 * their local `$vendorDir`/`$baseDir` variables never leak into this class.
 * The maps are then fed into a fresh `Composer\Autoload\ClassLoader` instance
 * that is never `register()`ed, so it never participates in autoloading
 * either.
 *
 * @internal
 */
final class ComposerLoaderVerifier
{
    private ClassLoader $loader;

    /** @var array<string, true> realpath-normalized `autoload.files` targets */
    private array $eagerFiles = [];

    public function __construct(string $repoRoot)
    {
        if (!class_exists(ClassLoader::class)) {
            throw new AnalyzerException('Composer\Autoload\ClassLoader is not available');
        }

        $composerDir = rtrim($repoRoot, '/') . '/vendor/composer';

        $psr4 = self::loadPathMap($composerDir . '/autoload_psr4.php');
        $namespaces = self::loadPathMap($composerDir . '/autoload_namespaces.php');
        $classmap = self::loadClassMap($composerDir . '/autoload_classmap.php');
        $files = is_file($composerDir . '/autoload_files.php')
            ? self::loadFilesMap($composerDir . '/autoload_files.php')
            : [];

        $loader = new ClassLoader();
        foreach ($psr4 as $prefix => $paths) {
            $loader->setPsr4($prefix, $paths);
        }
        foreach ($namespaces as $prefix => $paths) {
            $loader->set($prefix, $paths);
        }
        $loader->addClassMap($classmap);
        // Deliberately never register()ed: this loader exists only to answer
        // findFile() questions, not to participate in autoloading.
        $this->loader = $loader;

        foreach ($files as $file) {
            $real = realpath($file);
            if ($real !== false) {
                $this->eagerFiles[$real] = true;
            }
        }
    }

    /**
     * Reports whether Composer's dumped autoload maps are present for the
     * given repository root, i.e. whether a ComposerLoaderVerifier can be
     * constructed for it at all.
     */
    public static function isAvailable(string $repoRoot): bool
    {
        $composerDir = rtrim($repoRoot, '/') . '/vendor/composer';

        return is_file($composerDir . '/autoload_psr4.php')
            && is_file($composerDir . '/autoload_namespaces.php')
            && is_file($composerDir . '/autoload_classmap.php');
    }

    /**
     * Asks Composer's own (dumped) ClassLoader where the given class would
     * load from, and compares it against the file depone statically resolved
     * it to.
     *
     * @return array{status: 'verified', loaderPath: null}|array{status: 'unknown', loaderPath: null}|array{status: 'mismatch', loaderPath: string}
     */
    public function verifyClass(string $class, string $targetAbsolute): array
    {
        $found = $this->loader->findFile($class);
        if ($found === false) {
            // Absent from the dump entirely: the dump is stale, or the class
            // was renamed/removed since the last `composer dump-autoload`.
            return ['status' => 'unknown', 'loaderPath' => null];
        }

        $foundReal = realpath($found);
        $targetReal = realpath($targetAbsolute);
        if ($foundReal !== false && $foundReal === $targetReal) {
            return ['status' => 'verified', 'loaderPath' => null];
        }

        return ['status' => 'mismatch', 'loaderPath' => $found];
    }

    /**
     * Reports whether the given absolute path is one of the `autoload.files`
     * entries in Composer's dump.
     */
    public function verifyEagerTarget(string $targetAbsolute): bool
    {
        $real = realpath($targetAbsolute);

        return $real !== false && isset($this->eagerFiles[$real]);
    }

    /**
     * Loads a Composer-generated map file -- a pure `return array(...)`
     * document -- through a scope-isolated closure, so its local
     * `$vendorDir`/`$baseDir` variables never leak into this class, and
     * validates that it actually returned an array.
     *
     * @return array<array-key, mixed>
     */
    private static function loadMap(string $path): array
    {
        $map = (static function (string $path) {
            return require $path;
        })($path);

        if (!is_array($map)) {
            throw new AnalyzerException("composer autoload map did not return an array: {$path}");
        }

        return $map;
    }

    /**
     * Loads a PSR-4/PSR-0 prefix map (`autoload_psr4.php`,
     * `autoload_namespaces.php`): prefix => one or several directories.
     *
     * @return array<string, list<string>|string>
     */
    private static function loadPathMap(string $path): array
    {
        $map = [];
        foreach (self::loadMap($path) as $prefix => $paths) {
            if (!is_string($prefix)) {
                throw new AnalyzerException("composer autoload map has a non-string prefix: {$path}");
            }

            if (is_string($paths)) {
                $map[$prefix] = $paths;
                continue;
            }

            if (!is_array($paths)) {
                throw new AnalyzerException("composer autoload map has an invalid path list: {$path}");
            }

            $list = [];
            foreach ($paths as $item) {
                if (!is_string($item)) {
                    throw new AnalyzerException("composer autoload map has a non-string path: {$path}");
                }
                $list[] = $item;
            }
            $map[$prefix] = $list;
        }

        return $map;
    }

    /**
     * Loads the classmap (`autoload_classmap.php`): class => file.
     *
     * @return array<string, string>
     */
    private static function loadClassMap(string $path): array
    {
        $map = [];
        foreach (self::loadMap($path) as $class => $file) {
            if (!is_string($class) || !is_string($file)) {
                throw new AnalyzerException("composer autoload map has a non-string entry: {$path}");
            }
            $map[$class] = $file;
        }

        return $map;
    }

    /**
     * Loads the `autoload.files` map (`autoload_files.php`): hash => file.
     * Only the files themselves matter here, not their hashes.
     *
     * @return list<string>
     */
    private static function loadFilesMap(string $path): array
    {
        $files = [];
        foreach (self::loadMap($path) as $file) {
            if (!is_string($file)) {
                throw new AnalyzerException("composer autoload map has a non-string entry: {$path}");
            }
            $files[] = $file;
        }

        return $files;
    }
}
