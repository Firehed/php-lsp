<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * No `#[CoversClass]`: the rule under test is dev tooling under `tests/`, not a
 * coverage target, and naming it as one is a PHPUnit error.
 *
 * @extends RuleTestCase<SymbolDiscoveryAuthorityRule>
 */
class SymbolDiscoveryAuthorityRuleTest extends RuleTestCase
{
    public function testConsumerReachingForConfinedCollaboratorsIsReported(): void
    {
        $this->analyse(
            [__DIR__ . '/data/consumer-imports-confined-collaborators.php'],
            [
                [self::message('Firehed\PhpLsp\Index\ComposerAutoloadMap'), 8],
                [self::message('Firehed\PhpLsp\Index\NamespaceCatalog'), 9],
                [self::message('Firehed\PhpLsp\Index\SymbolIndex'), 10],
                [self::message('Firehed\PhpLsp\Repository\ClassRepository'), 11],
                [self::message('ReflectionClass'), 13],
            ],
        );
    }

    public function testTheBackendPackageMayNameTheCollaborators(): void
    {
        $this->analyse([__DIR__ . '/data/backend-imports-confined-collaborators.php'], []);
    }

    public function testTheCompositionRootMayNameTheCollaborators(): void
    {
        $this->analyse([__DIR__ . '/data/composition-root-imports-confined-collaborators.php'], []);
    }

    public function testTheTestNamespaceMayUseReflection(): void
    {
        $this->analyse([__DIR__ . '/data/tests-import-reflection.php'], []);
    }

    protected function getRule(): Rule
    {
        return new SymbolDiscoveryAuthorityRule();
    }

    private static function message(string $fqcn): string
    {
        return $fqcn . ' is a symbol-discovery backend collaborator and must not be used outside a '
            . 'SymbolSource/SymbolSink backend; depend on the Knowledge seam instead (RFC 1 §4.2).';
    }
}
