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
                    'instanceof MethodInfo branches on concrete ResolvedSymbolInterface; '
                        . 'use predicates.',
                    19,
                ],
                [
                    'instanceof MethodInfo branches on concrete ResolvedSymbolInterface; '
                        . 'use predicates.',
                    26,
                ],
                [
                    'instanceof MethodInfo branches on concrete ResolvedSymbolInterface; '
                        . 'use predicates.',
                    32,
                ],
                [
                    'instanceof PropertyInfo branches on concrete ResolvedSymbolInterface; '
                        . 'use predicates.',
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
                    'instanceof ClasslikeType branches on concrete TypeInterface; '
                        . 'use predicates.',
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
