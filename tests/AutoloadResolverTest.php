<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Tests;

use PHPUnit\Framework\TestCase;
use RedundantRequireOnce\Resolver\AutoloadResolver;

/**
 * Unit tests for AutoloadResolver.
 *
 * Covered behavior:
 *   PSR-4 resolution (basic, subdirectory, longer prefix wins)
 *   PSR-0 resolution (underscore-to-slash conversion, namespaced forms)
 *   classmap resolution (class / interface / trait)
 *   classmap taking precedence over PSR-4
 *   loading rules from autoload-dev
 *   stripping a leading backslash
 *   returning null when composer.json is missing
 *
 * Fixture: tests/Fixture/AutoloadResolverProject/
 */
final class AutoloadResolverTest extends TestCase
{
    private static string $root;
    private static AutoloadResolver $resolver;

    public static function setUpBeforeClass(): void
    {
        $path = realpath(__DIR__ . '/Fixture/AutoloadResolverProject');
        self::assertNotFalse($path, 'Fixture directory not found');
        self::$root = $path;
        self::$resolver = new AutoloadResolver($path);
    }

    // -------------------------------------------------------------------------
    // PSR-4 resolution
    // -------------------------------------------------------------------------

    public function testPsr4BasicResolution(): void
    {
        $result = self::$resolver->resolve('App\Foo');
        self::assertSame(self::$root . '/src/Foo.php', $result);
    }

    public function testPsr4SubdirectoryResolution(): void
    {
        $result = self::$resolver->resolve('App\Sub\Bar');
        self::assertSame(self::$root . '/src/Sub/Bar.php', $result);
    }

    public function testPsr4InterfaceResolution(): void
    {
        // Interfaces should resolve through PSR-4 path rules as well.
        $result = self::$resolver->resolve('App\Contracts\MyInterface');
        self::assertSame(self::$root . '/src/Contracts/MyInterface.php', $result);
    }

    public function testPsr4TraitResolution(): void
    {
        // Traits should also resolve through PSR-4 path rules.
        $result = self::$resolver->resolve('App\Contracts\MyTrait');
        self::assertSame(self::$root . '/src/Contracts/MyTrait.php', $result);
    }

    public function testPsr4LeadingBackslashIsStripped(): void
    {
        // \App\Foo should resolve the same as App\Foo.
        $result = self::$resolver->resolve('\App\Foo');
        self::assertSame(self::$root . '/src/Foo.php', $result);
    }

    public function testPsr4NonExistentFileReturnsNull(): void
    {
        // Classes whose files do not exist should return null.
        self::assertNull(self::$resolver->resolve('App\DoesNotExist'));
    }

    public function testPsr4NoPrefixMatchReturnsNull(): void
    {
        // Classes with no matching prefix should return null.
        self::assertNull(self::$resolver->resolve('Unknown\SomeClass'));
    }

    public function testPsr4LongerPrefixTakesPriority(): void
    {
        // When both App\ and App\Specific\ exist, the longer prefix wins.
        // App\Specific\SpecificFoo -> src/specific/SpecificFoo.php
        // The broader App\ rule would look for src/Specific/SpecificFoo.php instead.
        $result = self::$resolver->resolve('App\Specific\SpecificFoo');
        self::assertSame(self::$root . '/src/specific/SpecificFoo.php', $result);
    }

    // -------------------------------------------------------------------------
    // PSR-0 resolution
    // -------------------------------------------------------------------------

    public function testPsr0UnderscoreConvertedToSlash(): void
    {
        // Legacy_Component -> legacy/Legacy/Component.php
        // PSR-0 converts underscores in the class name to directory separators.
        $result = self::$resolver->resolve('Legacy_Component');
        self::assertSame(self::$root . '/legacy/Legacy/Component.php', $result);
    }

    public function testPsr0WithNamespaceAndUnderscore(): void
    {
        // Namespaced PSR-0 form:
        //   Legacy\Sub\Class_Name -> legacy/ + Legacy/Sub/ + Class/Name.php
        // This file does not exist, so the result should be null.
        self::assertNull(self::$resolver->resolve('Legacy\Sub\Class_Name'));
    }

    // -------------------------------------------------------------------------
    // classmap resolution
    // -------------------------------------------------------------------------

    public function testClassmapClassResolution(): void
    {
        $result = self::$resolver->resolve('Mapped\MappedClass');
        self::assertSame(self::$root . '/classmap/MappedClass.php', $result);
    }

    public function testClassmapInterfaceIsDetected(): void
    {
        // Interfaces detected by scanFileForClasses should be added to the classmap.
        $result = self::$resolver->resolve('Mapped\MappedInterface');
        self::assertSame(self::$root . '/classmap/MappedInterface.php', $result);
    }

    public function testClassmapTraitIsDetected(): void
    {
        // Traits detected by scanFileForClasses should be added to the classmap.
        $result = self::$resolver->resolve('Mapped\MappedTrait');
        self::assertSame(self::$root . '/classmap/MappedTrait.php', $result);
    }

    public function testClassmapTakesPriorityOverPsr4(): void
    {
        // App\OverriddenClass is resolvable via PSR-4 as well, but the classmap
        // entry should win because it is checked first.
        $result = self::$resolver->resolve('App\OverriddenClass');
        self::assertSame(self::$root . '/classmap/OverriddenClass.php', $result);
        // Ensure the PSR-4 file is not returned instead.
        self::assertNotSame(self::$root . '/src/OverriddenClass.php', $result);
    }

    // -------------------------------------------------------------------------
    // autoload-dev loading
    // -------------------------------------------------------------------------

    public function testAutoloadDevClassIsResolved(): void
    {
        // The autoload-dev PSR-4 rule (App\Tests\ -> dev-tests/) should also work.
        $result = self::$resolver->resolve('App\Tests\FooTest');
        self::assertSame(self::$root . '/dev-tests/FooTest.php', $result);
    }

    // -------------------------------------------------------------------------
    // Missing composer.json
    // -------------------------------------------------------------------------

    public function testMissingComposerJsonResultsInNullResolve(): void
    {
        $tmpDir = sys_get_temp_dir() . '/autoload_resolver_test_' . uniqid('', true);
        mkdir($tmpDir);
        try {
            $resolver = new AutoloadResolver($tmpDir);
            self::assertNull($resolver->resolve('Any\Class'));
            self::assertSame([], $resolver->getPsr4Rules());
            self::assertSame([], $resolver->getClassmap());
        } finally {
            rmdir($tmpDir);
        }
    }

    // -------------------------------------------------------------------------
    // getPsr4Rules / getClassmap (debug helpers)
    // -------------------------------------------------------------------------

    public function testGetPsr4RulesContainsAllMappings(): void
    {
        $rules = self::$resolver->getPsr4Rules();

        // Rules from both autoload and autoload-dev should be present.
        self::assertArrayHasKey('App\\', $rules);
        self::assertArrayHasKey('App\\Specific\\', $rules);
        self::assertArrayHasKey('App\\Tests\\', $rules);

        self::assertSame(self::$root . '/src', $rules['App\\']);
        self::assertSame(self::$root . '/src/specific', $rules['App\\Specific\\']);
        self::assertSame(self::$root . '/dev-tests', $rules['App\\Tests\\']);
    }

    public function testGetPsr4RulesAreSortedByPrefixLengthDescending(): void
    {
        $rules = self::$resolver->getPsr4Rules();
        $keys = array_keys($rules);

        // Earlier entries should be at least as long as later prefixes.
        for ($i = 0; $i < count($keys) - 1; $i++) {
            self::assertGreaterThanOrEqual(
                strlen($keys[$i + 1]),
                strlen($keys[$i]),
                "Key '{$keys[$i]}' should be >= '{$keys[$i + 1]}' in length"
            );
        }
    }

    public function testGetClassmapContainsScannedClasses(): void
    {
        $classmap = self::$resolver->getClassmap();

        self::assertArrayHasKey('Mapped\\MappedClass', $classmap);
        self::assertArrayHasKey('Mapped\\MappedInterface', $classmap);
        self::assertArrayHasKey('Mapped\\MappedTrait', $classmap);
        self::assertArrayHasKey('App\\OverriddenClass', $classmap);

        self::assertSame(self::$root . '/classmap/MappedClass.php', $classmap['Mapped\\MappedClass']);
        self::assertSame(self::$root . '/classmap/MappedInterface.php', $classmap['Mapped\\MappedInterface']);
        self::assertSame(self::$root . '/classmap/MappedTrait.php', $classmap['Mapped\\MappedTrait']);
        self::assertSame(self::$root . '/classmap/OverriddenClass.php', $classmap['App\\OverriddenClass']);
    }
}
