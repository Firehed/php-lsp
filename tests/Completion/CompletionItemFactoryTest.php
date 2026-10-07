<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\CompletionItemKind;
use Firehed\PhpLsp\Completion\InsertTextFormat;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Protocol\Range;
use Firehed\PhpLsp\Resolution\PresentedSymbol;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionItemFactory::class)]
final class CompletionItemFactoryTest extends TestCase
{
    public function testNamedArgumentSortsAheadOfEveryOtherItem(): void
    {
        $parameter = new ParameterInfo('count', new PrimitiveType('int'), true, '0', 1, false, false);

        self::assertSame(
            [
                'label' => 'count:',
                'kind' => CompletionItemKind::Field->value,
                'detail' => 'int $count',
                'sortText' => '!count:',
            ],
            CompletionItemFactory::forNamedArgument($parameter),
            'a named argument shows its signature and sorts first, so a capped list keeps it',
        );
    }

    public function testBuiltinTypeSortsAheadOfClassLikes(): void
    {
        self::assertSame(
            [
                'label' => 'string',
                'kind' => CompletionItemKind::Keyword->value,
                'detail' => 'builtin type',
                'sortText' => '!string',
            ],
            CompletionItemFactory::forBuiltinType('string'),
            'a built-in type sorts first, so a capped list keeps it',
        );
    }

    public function testKeywordIsJustItsLabel(): void
    {
        self::assertSame(
            ['label' => 'foreach', 'kind' => CompletionItemKind::Keyword->value],
            CompletionItemFactory::forKeyword('foreach'),
            'a keyword carries no detail',
        );
    }

    public function testVariableShowsItsType(): void
    {
        self::assertSame(
            ['label' => '$user', 'kind' => CompletionItemKind::Variable->value, 'detail' => 'Fixtures\Domain\User'],
            CompletionItemFactory::forVariable('user', 'Fixtures\Domain\User'),
            'a variable is labelled with its sigil and shows its type',
        );
    }

    public function testClassConstantDescribesWhatItHolds(): void
    {
        self::assertSame(
            [
                'label' => 'class',
                'kind' => CompletionItemKind::Constant->value,
                'detail' => 'string (fully qualified class name)',
            ],
            CompletionItemFactory::forClassConstant(),
            '::class holds the fully qualified name',
        );
    }

    public function testClassLikeSymbolReplacesThePrefixWithItsReference(): void
    {
        self::assertSame(
            [
                'label' => 'Models\User',
                'kind' => CompletionItemKind::Class_->value,
                'detail' => 'App\Models\User',
                'filterText' => 'User',
                'textEdit' => [
                    'range' => ['start' => ['line' => 3, 'character' => 8], 'end' => ['line' => 3, 'character' => 10]],
                    'newText' => 'Models\User',
                ],
            ],
            CompletionItemFactory::forSymbol(
                'Models\User',
                'App\Models\User',
                NameKind::ClassLike,
                Range::onLine(3, 8, 10),
            ),
            'the reference replaces the typed prefix, filters by its short name, and shows the full name',
        );
    }

    /**
     * @return iterable<string, array{NameKind, bool, int, ?string}>
     */
    public static function symbolKinds(): iterable
    {
        yield 'function' => [NameKind::Function_, false, CompletionItemKind::Function->value, null];
        yield 'function with snippets' => [NameKind::Function_, true, CompletionItemKind::Function->value, 'wrap($0)'];
        yield 'constant with snippets' => [NameKind::Constant, true, CompletionItemKind::Constant->value, null];
    }

    #[DataProvider('symbolKinds')]
    public function testSymbolKindDecidesItsItemKindAndCallSnippet(
        NameKind $kind,
        bool $snippetSupport,
        int $itemKind,
        ?string $insertText,
    ): void {
        $item = CompletionItemFactory::forSymbol('wrap', 'Lib\wrap', $kind, Range::onLine(0, 0, 0), $snippetSupport);

        self::assertSame($itemKind, $item['kind'] ?? null, 'the item kind follows the symbol kind');
        self::assertSame(
            $insertText,
            $item['insertText'] ?? null,
            'only a callable gets call parentheses, and only when the client takes snippets',
        );
    }

    public function testMethodCarriesItsDescriptionAndCallSnippet(): void
    {
        $method = new MethodInfo(
            name: new MethodName(ClasslikeName::fromFullyQualified('Fixtures\Domain\User'), 'save'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: null,
            docblock: "/**\n * Persists the user.\n */",
            file: null,
            line: null,
        );

        $item = CompletionItemFactory::forResolvedMember($method, snippetSupport: true);

        self::assertSame(CompletionItemKind::Method->value, $item['kind'] ?? null, 'a method is a method item');
        self::assertSame('Persists the user.', $item['documentation'] ?? null, 'the docblock description is shown');
        self::assertSame(
            ['save($0)', InsertTextFormat::Snippet->value],
            [$item['insertText'] ?? null, $item['insertTextFormat'] ?? null],
            'a method gets call parentheses when the client takes snippets',
        );
    }

    public function testPropertyNeverGetsACallSnippet(): void
    {
        $property = new PropertyInfo(
            name: new PropertyName(ClasslikeName::fromFullyQualified('Fixtures\Domain\User'), 'name'),
            visibility: Visibility::Public,
            isStatic: false,
            isReadonly: false,
            isPromoted: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertArrayNotHasKey(
            'insertText',
            CompletionItemFactory::forResolvedMember($property, snippetSupport: true),
            'a property is not callable, so no parentheses are inserted even when the client takes snippets',
        );
    }

    public function testNamespaceIsANodeEndingInASeparator(): void
    {
        self::assertSame(
            [
                'label' => 'Http\\',
                'kind' => CompletionItemKind::Module->value,
                'detail' => 'Psr\Http',
                'filterText' => 'Http',
                'textEdit' => [
                    'range' => ['start' => ['line' => 2, 'character' => 4], 'end' => ['line' => 2, 'character' => 6]],
                    'newText' => 'Http\\',
                ],
            ],
            CompletionItemFactory::forNamespace('Http', 'Psr\Http', Range::onLine(2, 4, 6)),
            'the inserted text carries the separator, so accepting it is ready for the next segment',
        );
    }

    public function testPresentedSymbolShowsItsSignatureAndDocumentation(): void
    {
        $item = CompletionItemFactory::forSymbol(
            'User',
            'App\Models\User',
            NameKind::ClassLike,
            Range::onLine(0, 0, 0),
            presented: new PresentedSymbol('class User', 'A person who signs in.'),
        );

        self::assertSame('class User', $item['detail'] ?? null, 'the presented signature replaces the full name');
        self::assertSame('A person who signs in.', $item['documentation'] ?? null, 'the description is documentation');
    }

    public function testResolvedMemberShowsItsSignature(): void
    {
        $case = new EnumCaseInfo(
            name: new EnumCaseName(ClasslikeName::fromFullyQualified('Fixtures\Enum\Priority'), 'Low'),
            backingValue: 1,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame(
            [
                'label' => 'Low',
                'kind' => CompletionItemKind::EnumMember->value,
                'detail' => 'case Low = 1',
            ],
            CompletionItemFactory::forResolvedMember($case),
            'a member shows its signature as the detail',
        );
    }
}
