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
use PHPUnit\Framework\TestCase;

#[CoversClass(BuiltinTypeCandidates::class)]
final class BuiltinTypeCandidatesTest extends TestCase
{
    public function testOffersPrimitivesAndKeywordsValidInThePosition(): void
    {
        $candidates = new BuiltinTypeCandidates(new CompletionItemFormatter(new ResolvedSymbolPresenter()));

        $labels = array_column($candidates->find(TypeHintContext::Property, ''), 'label');

        self::assertContains(PrimitiveType::Int->value, $labels, 'primitives are offered');
        self::assertContains(LateBindingKeyword::Self->value, $labels, 'late-binding keywords are offered');
        self::assertNotContains(PrimitiveType::Callable->value, $labels, 'a primitive invalid here is withheld');
        self::assertNotContains(LateBindingKeyword::Static->value, $labels, 'a keyword invalid here is withheld');
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
