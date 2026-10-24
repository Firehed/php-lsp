<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\BuiltinTypeCandidates;
use Firehed\PhpLsp\Completion\CompletionItemFormatter;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\TypeHintContext;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BuiltinTypeCandidates::class)]
final class BuiltinTypeCandidatesTest extends TestCase
{
    /**
     * @return iterable<string, array{TypeHintContext}>
     */
    public static function contexts(): iterable
    {
        foreach (TypeHintContext::cases() as $context) {
            yield $context->name => [$context];
        }
    }

    #[DataProvider('contexts')]
    public function testOffersTheTypesValidInThePosition(TypeHintContext $context): void
    {
        $candidates = new BuiltinTypeCandidates(new CompletionItemFormatter(new ResolvedSymbolPresenter()));
        $valid = array_filter(
            [...PrimitiveType::cases(), ...LateBindingKeyword::cases()],
            static fn (PrimitiveType|LateBindingKeyword $type): bool => $type->validIn($context),
        );

        self::assertEqualsCanonicalizing(
            array_column($valid, 'value'),
            array_column($candidates->find($context, ''), 'label'),
            'each position offers every built-in type PHP accepts there, and no other',
        );
    }

    public function testFiltersByThePrefix(): void
    {
        $candidates = new BuiltinTypeCandidates(new CompletionItemFormatter(new ResolvedSymbolPresenter()));

        self::assertSame(
            ['string'],
            array_column(
                $candidates->find(TypeHintContext::Parameter, 'str'),
                'label',
            ),
            'only types starting with what was typed',
        );
    }
}
