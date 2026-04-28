<?php

declare(strict_types=1);

namespace RedundantRequireOnce\Tests;

use PHPUnit\Framework\TestCase;
use RedundantRequireOnce\Resolver\ClassReferenceDetector;

/**
 * Unit tests for ClassReferenceDetector.
 *
 * Grouped by detected reference kind:
 *   new expressions / extends / implements / static access (::) / instanceof
 *   catch / type hints / use statements / namespace resolution / deduplication
 */
final class ClassReferenceDetectorTest extends TestCase
{
    /**
     * Runs detect() on PHP source code and returns the sorted result.
     *
     * @return list<string>
     */
    private function detect(string $phpCode): array
    {
        $result = (new ClassReferenceDetector())->detect($phpCode);
        sort($result);
        return array_values($result);
    }

    /**
     * Wraps the body in a `namespace App;` block and runs detect().
     *
     * @return list<string>
     */
    private function detectInNs(string $body, string $ns = 'App'): array
    {
        return $this->detect("<?php\nnamespace {$ns};\n{$body}");
    }

    // -------------------------------------------------------------------------
    // new expressions
    // -------------------------------------------------------------------------

    public function testNewSimpleClass(): void
    {
        // Unqualified names should resolve within the current namespace.
        self::assertSame(['App\Foo'], $this->detectInNs('$x = new Foo();'));
    }

    public function testNewResolvedViaUseStatement(): void
    {
        $code = 'use Other\Foo; $x = new Foo();';
        self::assertSame(['Other\Foo'], $this->detectInNs($code));
    }

    public function testNewWithUseAlias(): void
    {
        $code = 'use Other\Foo as F; $x = new F();';
        self::assertSame(['Other\Foo'], $this->detectInNs($code));
    }

    public function testNewFullyQualifiedClass(): void
    {
        // Leading backslashes should still resolve correctly.
        self::assertSame(['Other\Foo'], $this->detectInNs('$x = new \Other\Foo();'));
    }

    public function testNewSelfExcluded(): void
    {
        self::assertSame([], $this->detectInNs('$x = new self();'));
    }

    public function testNewStaticKeywordExcluded(): void
    {
        // T_STATIC is excluded by parseClassNameAfter().
        self::assertSame([], $this->detectInNs('$x = new static();'));
    }

    public function testNewParentExcluded(): void
    {
        self::assertSame([], $this->detectInNs('$x = new parent();'));
    }

    public function testNewVariableExcluded(): void
    {
        // new $variable is dynamic instantiation and should be ignored.
        self::assertSame([], $this->detectInNs('$x = new $class();'));
    }

    // -------------------------------------------------------------------------
    // extends
    // -------------------------------------------------------------------------

    public function testExtendsDetected(): void
    {
        self::assertSame(['App\Base'], $this->detectInNs('class Child extends Base {}'));
    }

    public function testExtendsResolvedViaUseStatement(): void
    {
        $code = 'use Other\Base; class Child extends Base {}';
        self::assertSame(['Other\Base'], $this->detectInNs($code));
    }

    // -------------------------------------------------------------------------
    // implements
    // -------------------------------------------------------------------------

    public function testImplementsSingleInterface(): void
    {
        self::assertSame(['App\Countable'], $this->detectInNs('class Foo implements Countable {}'));
    }

    public function testImplementsMultipleInterfaces(): void
    {
        self::assertSame(
            ['App\Countable', 'App\Stringable'],
            $this->detectInNs('class Foo implements Stringable, Countable {}')
        );
    }

    public function testImplementsResolvedViaUseStatement(): void
    {
        $code = 'use Psr\Log\LoggerAwareInterface; class Foo implements LoggerAwareInterface {}';
        self::assertSame(['Psr\Log\LoggerAwareInterface'], $this->detectInNs($code));
    }

    // -------------------------------------------------------------------------
    // Static access (::)
    // -------------------------------------------------------------------------

    public function testStaticMethodCallDetected(): void
    {
        self::assertSame(['App\Registry'], $this->detectInNs('Registry::getInstance();'));
    }

    public function testStaticConstantAccessDetected(): void
    {
        self::assertSame(['App\Config'], $this->detectInNs('$v = Config::VERSION;'));
    }

    public function testSelfStaticCallExcluded(): void
    {
        self::assertSame([], $this->detectInNs('class Foo { public function f() { self::bar(); } }'));
    }

    public function testParentStaticCallExcluded(): void
    {
        self::assertSame([], $this->detectInNs('class Foo { public function f() { parent::bar(); } }'));
    }

    public function testStaticKeywordCallExcluded(): void
    {
        // T_STATIC is excluded by parseClassNameBefore().
        self::assertSame([], $this->detectInNs('class Foo { public function f() { static::bar(); } }'));
    }

    public function testVariableStaticCallExcluded(): void
    {
        // $var::method() is dynamic and should be ignored.
        self::assertSame([], $this->detectInNs('$var::method();'));
    }

    // -------------------------------------------------------------------------
    // instanceof
    // -------------------------------------------------------------------------

    public function testInstanceofDetected(): void
    {
        self::assertSame(['App\Foo'], $this->detectInNs('$ok = $x instanceof Foo;'));
    }

    public function testInstanceofResolvedViaUse(): void
    {
        $code = 'use Other\Foo; $ok = $x instanceof Foo;';
        self::assertSame(['Other\Foo'], $this->detectInNs($code));
    }

    // -------------------------------------------------------------------------
    // catch
    // -------------------------------------------------------------------------

    public function testCatchSingleException(): void
    {
        $code = 'try {} catch (MyException $e) {}';
        self::assertSame(['App\MyException'], $this->detectInNs($code));
    }

    public function testCatchMultipleExceptionsUnion(): void
    {
        // PHP 8 multi-catch (|)
        $code = 'try {} catch (FooException|BarException $e) {}';
        self::assertSame(['App\BarException', 'App\FooException'], $this->detectInNs($code));
    }

    public function testCatchResolvedViaUse(): void
    {
        $code = 'use Http\ClientException; try {} catch (ClientException $e) {}';
        self::assertSame(['Http\ClientException'], $this->detectInNs($code));
    }

    // -------------------------------------------------------------------------
    // Type hints (parameters and returns)
    // -------------------------------------------------------------------------

    public function testParameterTypeHintDetected(): void
    {
        self::assertSame(['App\Bar'], $this->detectInNs('function foo(Bar $b) {}'));
    }

    public function testReturnTypeHintDetected(): void
    {
        self::assertSame(['App\Bar'], $this->detectInNs('function foo(): Bar {}'));
    }

    public function testNullableParameterTypeHintDetected(): void
    {
        // ?Bar should still register Bar as a reference.
        self::assertSame(['App\Bar'], $this->detectInNs('function foo(?Bar $b) {}'));
    }

    public function testUnionParameterTypeHintDetected(): void
    {
        self::assertSame(
            ['App\Bar', 'App\Foo'],
            $this->detectInNs('function foo(Foo|Bar $b) {}')
        );
    }

    public function testUnionReturnTypeHintDetected(): void
    {
        self::assertSame(
            ['App\Bar', 'App\Foo'],
            $this->detectInNs('function foo(): Foo|Bar {}')
        );
    }

    public function testScalarTypeHintsAreExcluded(): void
    {
        $code = 'function foo(int $a, string $b, bool $c, float $d, array $e): void {}';
        self::assertSame([], $this->detectInNs($code));
    }

    public function testBuiltinTypeHintsAreExcluded(): void
    {
        // callable, iterable, object, mixed, null, false, true, and never are also excluded.
        $code = 'function foo(callable $a, iterable $b, object $c, mixed $d): never {}';
        self::assertSame([], $this->detectInNs($code));
    }

    public function testDefaultValueNotMistakenForTypeHint(): void
    {
        // The default value null should not be mistaken for a type hint.
        $code = 'function foo(Bar $b = null) {}';
        self::assertSame(['App\Bar'], $this->detectInNs($code));
    }

    public function testMultipleParameterTypeHintsDetected(): void
    {
        $code = 'function foo(Foo $a, Bar $b): Baz {}';
        self::assertSame(['App\Bar', 'App\Baz', 'App\Foo'], $this->detectInNs($code));
    }

    public function testTypeHintResolvedViaUse(): void
    {
        $code = 'use Other\Bar; function foo(Bar $b): void {}';
        self::assertSame(['Other\Bar'], $this->detectInNs($code));
    }

    // -------------------------------------------------------------------------
    // use statements
    // -------------------------------------------------------------------------

    public function testUseFunctionIsExcluded(): void
    {
        // use function should not be added to the use map or detected as a class reference.
        $code = 'use function Other\helper; helper();';
        self::assertSame([], $this->detectInNs($code));
    }

    public function testUseConstIsExcluded(): void
    {
        // use const should be excluded in the same way.
        $code = 'use const Other\SOME_CONST; $v = SOME_CONST;';
        self::assertSame([], $this->detectInNs($code));
    }

    // -------------------------------------------------------------------------
    // Namespace resolution
    // -------------------------------------------------------------------------

    public function testGlobalNamespaceReturnsClassName(): void
    {
        // Without a namespace declaration, the class name should be returned as-is.
        $code = '<?php $x = new Foo();';
        self::assertSame(['Foo'], $this->detect($code));
    }

    public function testSameNamespaceClassResolution(): void
    {
        // Same-namespace classes should resolve to namespace\ClassName.
        self::assertSame(['MyNs\MyClass'], $this->detectInNs('new MyClass();', 'MyNs'));
    }

    public function testFullyQualifiedNameLeadingBackslash(): void
    {
        // \Fully\Qualified\Name should be returned without the leading backslash.
        self::assertSame(
            ['Fully\Qualified\Name'],
            $this->detectInNs('$x = new \Fully\Qualified\Name();')
        );
    }

    // -------------------------------------------------------------------------
    // Deduplication / empty cases
    // -------------------------------------------------------------------------

    public function testDuplicateReferencesAreDeduped(): void
    {
        $code = 'new Foo(); new Foo(); Foo::bar(); $x instanceof Foo;';
        self::assertSame(['App\Foo'], $this->detectInNs($code));
    }

    public function testEmptyClassHasNoReferences(): void
    {
        self::assertSame([], $this->detectInNs('class Empty {}'));
    }

    public function testNoPhpCodeReturnsEmpty(): void
    {
        self::assertSame([], $this->detect('<?php // nothing here'));
    }

    // -------------------------------------------------------------------------
    // Attributes (PHP 8.0+)
    // -------------------------------------------------------------------------

    public function testSimpleAttribute(): void
    {
        self::assertSame(['App\Route'], $this->detectInNs('#[Route] class Foo {}'));
    }

    public function testAttributeWithArguments(): void
    {
        self::assertSame(['App\Route'], $this->detectInNs('#[Route("/api")] class Foo {}'));
    }

    public function testAttributeWithNamedArguments(): void
    {
        self::assertSame(['App\Column'], $this->detectInNs('#[Column(name: "id", type: "integer")] class Foo {}'));
    }

    public function testAttributeWithArrayArgument(): void
    {
        // `]` inside arguments must not be mistaken for the end of the attribute block.
        self::assertSame(['App\Column'], $this->detectInNs('#[Column(options: ["key" => "val"])] class Foo {}'));
    }

    public function testMultipleAttributesInOneBlock(): void
    {
        self::assertSame(['App\Attr1', 'App\Attr2'], $this->detectInNs('#[Attr1, Attr2] class Foo {}'));
    }

    public function testMultipleAttributeBlocks(): void
    {
        self::assertSame(['App\Attr1', 'App\Attr2'], $this->detectInNs('#[Attr1] #[Attr2] class Foo {}'));
    }

    public function testAttributeResolvedViaUse(): void
    {
        $code = 'use Symfony\Component\Routing\Attribute\Route; #[Route("/api")] class Foo {}';
        self::assertSame(['Symfony\Component\Routing\Attribute\Route'], $this->detectInNs($code));
    }

    public function testQualifiedAttributeInNamespace(): void
    {
        // Assert\NotBlank should resolve under the current namespace.
        self::assertSame(['App\Assert\NotBlank'], $this->detectInNs('#[Assert\NotBlank] class Foo {}'));
    }

    public function testFqcnAttribute(): void
    {
        self::assertSame(['Fully\Qualified\Attr'], $this->detectInNs('#[\Fully\Qualified\Attr] class Foo {}'));
    }

    public function testAttributeOnMethod(): void
    {
        $code = 'class Foo { #[Required] public function bar(): void {} }';
        self::assertSame(['App\Required'], $this->detectInNs($code));
    }

    public function testAttributeOnProperty(): void
    {
        $code = 'class Foo { #[Column] private Bar $bar; }';
        self::assertSame(['App\Bar', 'App\Column'], $this->detectInNs($code));
    }

    // -------------------------------------------------------------------------
    // Property type declarations
    // -------------------------------------------------------------------------

    public function testPrivateTypedProperty(): void
    {
        $code = 'class Foo { private Bar $bar; }';
        self::assertSame(['App\Bar'], $this->detectInNs($code));
    }

    public function testPublicTypedProperty(): void
    {
        $code = 'class Foo { public Baz $baz; }';
        self::assertSame(['App\Baz'], $this->detectInNs($code));
    }

    public function testProtectedTypedProperty(): void
    {
        $code = 'class Foo { protected Qux $qux; }';
        self::assertSame(['App\Qux'], $this->detectInNs($code));
    }

    public function testNullableTypedProperty(): void
    {
        $code = 'class Foo { public ?Bar $bar = null; }';
        self::assertSame(['App\Bar'], $this->detectInNs($code));
    }

    public function testUnionTypedProperty(): void
    {
        $code = 'class Foo { private Bar|Baz $prop; }';
        self::assertSame(['App\Bar', 'App\Baz'], $this->detectInNs($code));
    }

    public function testIntersectionTypedProperty(): void
    {
        $code = 'class Foo { private Bar&Baz $prop; }';
        self::assertSame(['App\Bar', 'App\Baz'], $this->detectInNs($code));
    }

    public function testReadonlyTypedProperty(): void
    {
        $code = 'class Foo { public readonly Bar $bar; }';
        self::assertSame(['App\Bar'], $this->detectInNs($code));
    }

    public function testStaticTypedProperty(): void
    {
        $code = 'class Foo { public static Bar $bar; }';
        self::assertSame(['App\Bar'], $this->detectInNs($code));
    }

    public function testScalarTypedPropertyIsIgnored(): void
    {
        $code = 'class Foo { public string $s; private int $n; protected bool $b; }';
        self::assertSame([], $this->detectInNs($code));
    }

    public function testUntypedPropertyIsIgnored(): void
    {
        $code = 'class Foo { public $noType; }';
        self::assertSame([], $this->detectInNs($code));
    }

    public function testMethodAfterVisibilityNotConfusedWithProperty(): void
    {
        $code = 'class Foo { public function foo(Bar $p): Baz {} }';
        self::assertSame(['App\Bar', 'App\Baz'], $this->detectInNs($code));
    }

    public function testConstAfterVisibilityNotDetected(): void
    {
        $code = 'class Foo { public const NAME = "value"; }';
        self::assertSame([], $this->detectInNs($code));
    }

    public function testPropertyTypeResolvedViaUse(): void
    {
        $code = 'use Other\Bar; class Foo { private Bar $bar; }';
        self::assertSame(['Other\Bar'], $this->detectInNs($code));
    }

    public function testMultipleTypedProperties(): void
    {
        $code = 'class Foo { private Bar $a; protected Baz $b; public Qux $c; }';
        self::assertSame(['App\Bar', 'App\Baz', 'App\Qux'], $this->detectInNs($code));
    }

    public function testPromotedConstructorParameterDetected(): void
    {
        // Constructor promotion is seen both as a type hint and a property type.
        // array_unique() should remove the duplicate reference.
        $code = 'class Foo { public function __construct(private Bar $bar) {} }';
        self::assertSame(['App\Bar'], $this->detectInNs($code));
    }
}
