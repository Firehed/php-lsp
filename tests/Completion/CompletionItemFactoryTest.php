<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\CompletionItemKind;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
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
