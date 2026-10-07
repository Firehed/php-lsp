<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\CompletionItemKind;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Protocol\Range;
use Firehed\PhpLsp\Resolution\PresentedSymbol;
use PHPUnit\Framework\Attributes\CoversClass;
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
