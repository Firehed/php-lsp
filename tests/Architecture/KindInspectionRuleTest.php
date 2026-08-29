<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<KindInspectionRule>
 */
class KindInspectionRuleTest extends RuleTestCase
{
    public function testInstanceofResolvedSymbolImplIsReported(): void
    {
        $this->analyse(
            [__DIR__ . '/data/instanceof-resolved-symbol.php'],
            [
                [
                    'instanceof MethodInfo branches on concrete ResolvedSymbol; '
                        . 'use predicates (RFC 1 §4.5).',
                    19,
                ],
                [
                    'instanceof MethodInfo branches on concrete ResolvedSymbol; '
                        . 'use predicates (RFC 1 §4.5).',
                    26,
                ],
                [
                    'instanceof MethodInfo branches on concrete ResolvedSymbol; '
                        . 'use predicates (RFC 1 §4.5).',
                    32,
                ],
                [
                    'instanceof PropertyInfo branches on concrete ResolvedSymbol; '
                        . 'use predicates (RFC 1 §4.5).',
                    33,
                ],
            ],
        );
    }

    public function testInstanceofTypeImplIsReported(): void
    {
        $this->analyse(
            [__DIR__ . '/data/instanceof-type.php'],
            [
                [
                    'instanceof ClassName branches on concrete Type; '
                        . 'use predicates (RFC 1 §4.5).',
                    18,
                ],
            ],
        );
    }

    public function testCompletionItemFactoryMayMapSymbolToKind(): void
    {
        $this->analyse([__DIR__ . '/../../src/Completion/CompletionItemFactory.php'], []);
    }

    protected function getRule(): Rule
    {
        return new KindInspectionRule();
    }
}
