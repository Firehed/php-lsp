<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\CompletionItemKind;
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
}
