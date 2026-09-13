<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\ClassNameUsageLocation;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * No `#[CoversClass]`: the extension under test is dev tooling under `tests/`, not a
 * coverage target, and naming it as one is a PHPUnit error.
 *
 * PHPStan owns the detection of *where* a class is referenced (every position and
 * spelling), so the test exercises the only thing this extension decides: given a
 * referenced class and the referencing namespace, is the reference confined? The real
 * `composer phpstan` run over `src/` is the end-to-end proof that the extension is
 * wired and fires.
 */
class SymbolDiscoveryAuthorityExtensionTest extends PHPStanTestCase
{
    private const string IDENTIFIER = 'phpLsp.symbolDiscoveryAuthority';

    /**
     * @param class-string $className
     */
    #[DataProvider('provideRestrictedReferences')]
    public function testConfinedCollaboratorReferencedOutsideABackendIsRestricted(
        string $className,
        ?string $namespace,
    ): void {
        $result = $this->decide($className, $namespace);

        self::assertNotNull(
            $result,
            $className . ' referenced from ' . ($namespace ?? 'the global namespace')
                . ' must be restricted (RFC 1 §4.2).',
        );
        self::assertSame(
            self::IDENTIFIER,
            $result->identifier,
            'The diagnostic must carry the stable §4.2 identifier.',
        );
        self::assertSame(
            $className . ' is a symbol-discovery backend collaborator and must not be referenced outside a '
                . 'SymbolSourceInterface/SymbolSinkInterface backend; depend on the Knowledge seam instead (RFC 1 §4.2).',
            $result->errorMessage,
            'The diagnostic must name the offending collaborator and cite §4.2.',
        );
    }

    /**
     * @param class-string $className
     */
    #[DataProvider('provideAllowedReferences')]
    public function testReferenceFromAnExemptContextIsAllowed(
        string $className,
        ?string $namespace,
    ): void {
        self::assertNull(
            $this->decide($className, $namespace),
            $className . ' referenced from ' . ($namespace ?? 'the global namespace')
                . ' must be allowed.',
        );
    }

    /**
     * Every confined-and-not-exempt collaborator, referenced from a production consumer
     * namespace, is restricted. A null namespace (global-namespace code) is a consumer
     * too. The function-path names are confined but exempted, so they appear among the
     * allowed references below, not here.
     *
     * @return iterable<string, array{class-string, ?string}>
     *
     * @codeCoverageIgnore
     */
    public static function provideRestrictedReferences(): iterable
    {
        yield 'autoload map from a consumer' => [ComposerAutoloadMap::class, 'Firehed\PhpLsp\Completion'];
        yield 'namespace catalog from a consumer' => [NamespaceCatalog::class, 'Firehed\PhpLsp\Completion'];
        yield 'reflection from a consumer' => [ReflectionClass::class, 'Firehed\PhpLsp\Completion'];
        yield 'autoload map from the global namespace' => [ComposerAutoloadMap::class, null];
    }

    /**
     * References that §4.2 permits: any confined collaborator from a backend package,
     * the composition root, or the test namespace; and any non-confined class.
     *
     * @return iterable<string, array{class-string, ?string}>
     *
     * @codeCoverageIgnore
     */
    public static function provideAllowedReferences(): iterable
    {
        yield 'namespace catalog from the Index backend' => [NamespaceCatalog::class, 'Firehed\PhpLsp\Index'];
        yield 'namespace catalog from a nested Index backend' => [NamespaceCatalog::class, 'Firehed\PhpLsp\Index\Sub'];
        yield 'namespace catalog from the Knowledge backend' => [NamespaceCatalog::class, 'Firehed\PhpLsp\Knowledge'];
        yield 'reflection from the Repository backend' => [ReflectionClass::class, 'Firehed\PhpLsp\Repository'];
        yield 'namespace catalog from the composition root' => [NamespaceCatalog::class, 'Firehed\PhpLsp'];
        yield 'reflection from the test namespace' => [ReflectionClass::class, 'Firehed\PhpLsp\Tests\Example'];
        yield 'non-confined class from a consumer' => [TextDocument::class, 'Firehed\PhpLsp\Completion'];
    }

    /**
     * @param class-string $className
     */
    private function decide(string $className, ?string $namespace): ?\PHPStan\Rules\RestrictedUsage\RestrictedUsage
    {
        $scope = self::createStub(Scope::class);
        $scope->method('getNamespace')->willReturn($namespace);

        return (new SymbolDiscoveryAuthorityExtension())->isRestrictedClassNameUsage(
            self::createReflectionProvider()->getClass($className),
            $scope,
            ClassNameUsageLocation::from(ClassNameUsageLocation::PARAMETER_TYPE),
        );
    }
}
