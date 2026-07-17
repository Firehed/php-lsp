<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionClassification;
use Firehed\PhpLsp\Completion\CompletionClassifier;
use Firehed\PhpLsp\Completion\CompletionKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The classifier consumes the text before the cursor (a line fragment), not a
 * whole file, so inputs are inline fragments rather than fixtures.
 */
#[CoversClass(CompletionClassifier::class)]
#[CoversClass(CompletionClassification::class)]
#[CoversClass(CompletionKind::class)]
class CompletionClassifierTest extends TestCase
{
    #[DataProvider('provideClassifications')]
    public function testClassify(string $textBeforeCursor, CompletionKind $kind, string $prefix): void
    {
        $result = CompletionClassifier::classify($textBeforeCursor);

        self::assertSame($kind, $result->kind, 'Classified kind should match');
        self::assertSame($prefix, $result->prefix, 'Extracted prefix should match');
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{string, CompletionKind, string}>
     */
    public static function provideClassifications(): iterable
    {
        yield 'variable with prefix' => ['$fo', CompletionKind::Variable, 'fo'];
        yield 'variable bare' => ['        $', CompletionKind::Variable, ''];

        yield 'new with prefix' => ['new Fo', CompletionKind::New_, 'Fo'];
        yield 'new bare' => ['new ', CompletionKind::New_, ''];

        yield 'after private' => ['    private fo', CompletionKind::AfterVisibility, 'fo'];
        yield 'after public bare' => ['    public ', CompletionKind::AfterVisibility, ''];
        yield 'after protected bare' => ['    protected ', CompletionKind::AfterVisibility, ''];

        yield 'return type plain' => ['    public function f(): Fo', CompletionKind::ReturnType, 'Fo'];
        yield 'return type bare' => ['    public function f(): ', CompletionKind::ReturnType, ''];
        yield 'return type nullable' => ['    public function f(): ?Fo', CompletionKind::ReturnType, 'Fo'];
        yield 'return type union' => ['    public function f(): int|Fo', CompletionKind::ReturnType, 'Fo'];
        yield 'return type intersection' => ['    public function f(): Foo&Ba', CompletionKind::ReturnType, 'Ba'];

        yield 'property type nullable' => ['    private ?Fo', CompletionKind::PropertyType, 'Fo'];
        yield 'property type union' => ['    public int|Fo', CompletionKind::PropertyType, 'Fo'];
        yield 'property type readonly intersection' => [
            '    protected readonly Foo&Ba',
            CompletionKind::PropertyType,
            'Ba',
        ];
        yield 'property type bare nullable' => ['    private ?', CompletionKind::PropertyType, ''];

        yield 'parameter type after paren' => ['    public function f(Fo', CompletionKind::ParameterType, 'Fo'];
        yield 'parameter type after comma' => ['    public function f(int $a, Ba', CompletionKind::ParameterType, 'Ba'];
        yield 'parameter type bare paren' => ['    public function f(', CompletionKind::ParameterType, ''];
        yield 'parameter type nullable' => ['    public function f(?Fo', CompletionKind::ParameterType, 'Fo'];

        // Leading-backslash (absolute) references keep the `\` and any interior
        // separators in the prefix, so namespace navigation reaches the handler
        // intact — the same treatment `new \Ps` already gets (#330).
        yield 'catch absolute' => ['        } catch (\\Ru', CompletionKind::Throwable, '\\Ru'];
        yield 'catch absolute qualified' => ['        } catch (\\App\\Ex', CompletionKind::Throwable, '\\App\\Ex'];
        yield 'multi-catch absolute continuation' => [
            '        } catch (Foo | \\Ba',
            CompletionKind::Throwable,
            '\\Ba',
        ];
        yield 'parameter type absolute' => ['    public function f(\\Da', CompletionKind::ParameterType, '\\Da'];
        yield 'parameter type absolute qualified' => [
            '    public function f(\\App\\Da',
            CompletionKind::ParameterType,
            '\\App\\Da',
        ];
        yield 'return type absolute' => ['    public function f(): \\Da', CompletionKind::ReturnType, '\\Da'];
        yield 'return type absolute nullable' => ['    public function f(): ?\\Da', CompletionKind::ReturnType, '\\Da'];
        yield 'property type absolute nullable' => ['    private ?\\Da', CompletionKind::PropertyType, '\\Da'];
        yield 'implements absolute' => ['class Foo implements \\My', CompletionKind::InterfaceList, '\\My'];
        yield 'implements absolute continuation' => [
            'class Foo implements A, \\My',
            CompletionKind::InterfaceList,
            '\\My',
        ];
        yield 'interface extends absolute' => [
            'interface Foo extends \\My',
            CompletionKind::InterfaceList,
            '\\My',
        ];
        yield 'class extends absolute' => ['class Foo extends \\Ba', CompletionKind::ExtendableClass, '\\Ba'];
        yield 'attribute absolute' => ['#[\\Ro', CompletionKind::Attribute, '\\Ro'];

        yield 'class body member' => ['class Foo { pub', CompletionKind::ClassBody, 'pub'];
        yield 'interface body member' => ['interface Foo { pub', CompletionKind::ClassBody, 'pub'];
        yield 'trait body member' => ['trait Foo { pub', CompletionKind::ClassBody, 'pub'];
        yield 'enum body member' => ['enum Foo { cas', CompletionKind::ClassBody, 'cas'];
        yield 'class body no word' => ['class Foo { ', CompletionKind::None, ''];
        yield 'class body brace only' => ['class Foo {', CompletionKind::None, ''];
        yield 'class body after completed method' => [
            'class Foo { function a() {} pub',
            CompletionKind::ClassBody,
            'pub',
        ];
        yield 'class body string with brace' => [
            'class Foo { const C = "{" ; pub',
            CompletionKind::ClassBody,
            'pub',
        ];
        yield 'class body string with escaped quote' => [
            'class Foo { const C = "a\\"b" ; pub',
            CompletionKind::ClassBody,
            'pub',
        ];

        yield 'implements with prefix' => ['class Foo implements My', CompletionKind::InterfaceList, 'My'];
        yield 'implements bare' => ['class Foo implements ', CompletionKind::InterfaceList, ''];
        yield 'implements list continuation' => ['class Foo implements A, My', CompletionKind::InterfaceList, 'My'];

        yield 'interface extends with prefix' => [
            'interface Foo extends My',
            CompletionKind::InterfaceList,
            'My',
        ];
        yield 'interface extends bare' => ['interface Foo extends ', CompletionKind::InterfaceList, ''];
        yield 'interface extends list continuation' => [
            'interface Foo extends A, My',
            CompletionKind::InterfaceList,
            'My',
        ];

        yield 'attribute with prefix' => ['#[Ro', CompletionKind::Attribute, 'Ro'];
        yield 'attribute bare' => ['#[', CompletionKind::Attribute, ''];
        yield 'attribute grouped continuation' => ['#[Foo, Ro', CompletionKind::Attribute, 'Ro'];
        yield 'attribute grouped after args' => ['#[Foo(1), Ro', CompletionKind::Attribute, 'Ro'];
        yield 'attribute indented' => ['    #[Ro', CompletionKind::Attribute, 'Ro'];
        // Inside an attribute's argument list is a value/named-argument position, not
        // an attribute-name position, so it must not classify as Attribute.
        yield 'inside attribute args is not attribute name' => [
            '#[Route(Ro',
            CompletionKind::ParameterType,
            'Ro',
        ];

        // `class X extends` resolves to a single extendable class, distinct from the
        // interface-extends list (issues #312, #313).
        yield 'class extends with prefix' => [
            'class Foo extends Ba',
            CompletionKind::ExtendableClass,
            'Ba',
        ];
        yield 'class extends bare' => ['class Foo extends ', CompletionKind::ExtendableClass, ''];
        yield 'abstract class extends with prefix' => [
            'abstract class Foo extends Ba',
            CompletionKind::ExtendableClass,
            'Ba',
        ];

        // A catch clause accepts Throwable types only, single or `|`-separated (#314).
        yield 'catch with prefix' => ['        } catch (Ba', CompletionKind::Throwable, 'Ba'];
        yield 'catch bare' => ['        } catch (', CompletionKind::Throwable, ''];
        yield 'catch no space before paren' => ['        } catch(Ba', CompletionKind::Throwable, 'Ba'];
        yield 'multi-catch continuation' => [
            '        } catch (Foo | Ba',
            CompletionKind::Throwable,
            'Ba',
        ];
        yield 'multi-catch continuation without spaces' => [
            '        } catch (Foo|Ba',
            CompletionKind::Throwable,
            'Ba',
        ];
        yield 'multi-catch bare continuation' => [
            '        } catch (Foo\\Bar | ',
            CompletionKind::Throwable,
            '',
        ];
        // Once the caught variable is reached, the position is no longer a type.
        yield 'catch variable is not a type' => [
            '        } catch (Foo $e',
            CompletionKind::Variable,
            'e',
        ];

        // The right-hand side of `instanceof` is a class-like position (classes,
        // interfaces, enums), so it classifies distinctly from the surrounding
        // expression and keeps a leading `\` for navigation (#315).
        yield 'instanceof with prefix' => ['        if ($x instanceof Ba', CompletionKind::Instanceof_, 'Ba'];
        yield 'instanceof bare' => ['        if ($x instanceof ', CompletionKind::Instanceof_, ''];
        yield 'instanceof absolute' => ['        if ($x instanceof \\Ba', CompletionKind::Instanceof_, '\\Ba'];
        yield 'instanceof absolute qualified' => [
            '        if ($x instanceof \\App\\Ba',
            CompletionKind::Instanceof_,
            '\\App\\Ba',
        ];

        yield 'expression at start' => ['fo', CompletionKind::Expression, 'fo'];
        yield 'expression after assignment' => ['$x = fo', CompletionKind::Expression, 'fo'];
        yield 'expression inside method body' => [
            'class Foo { public function bar() { retur',
            CompletionKind::Expression,
            'retur',
        ];

        yield 'none empty' => ['', CompletionKind::None, ''];
        yield 'none after member arrow' => ['$foo->', CompletionKind::None, ''];
        yield 'none after operator no word' => ['1 + ', CompletionKind::None, ''];
    }
}
