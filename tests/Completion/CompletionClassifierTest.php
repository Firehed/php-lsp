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

        yield 'implements with prefix' => ['class Foo implements My', CompletionKind::Implements_, 'My'];
        yield 'implements bare' => ['class Foo implements ', CompletionKind::Implements_, ''];
        yield 'implements list continuation' => ['class Foo implements A, My', CompletionKind::Implements_, 'My'];

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
