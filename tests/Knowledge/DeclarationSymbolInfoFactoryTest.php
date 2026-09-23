<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Knowledge\FileDeclarations;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\TestCase;

/**
 * The single place a {@see NameKind} selects a declaration list and a builder.
 */
final class DeclarationSymbolInfoFactoryTest extends TestCase
{
    use LoadsFixturesTrait;

    /** Declares all three kinds, so a lookup reading the wrong list is visible. */
    private const string FIXTURE = 'AutoloadFiles/helpers.php';

    private DeclarationSymbolInfoFactory $factory;
    private FileDeclarations $declarations;
    private string $path;

    protected function setUp(): void
    {
        $this->factory = new DeclarationSymbolInfoFactory();
        $this->path = $this->fixturePath(self::FIXTURE);

        $production = ProductionSyntaxSource::create();
        $document = $production->reader->read($this->path);
        self::assertNotNull($document, 'the fixture must be readable so declarations can be scanned');
        $this->declarations = (new DeclarationScanner())->scan($production->source->parse($document));
    }

    public function testBuildsClassInfoForAClassLikeDeclaration(): void
    {
        $info = $this->buildClass('Fixtures\Helpers\HelperRegistry');

        self::assertNotNull($info, 'the located declaration must be built');
        self::assertSame(
            'Fixtures\Helpers\HelperRegistry',
            $info->name->qualifiedName->fullyQualifiedName(),
            'the located declaration must be built',
        );
    }

    public function testBuildsFunctionInfoForAFunctionDeclaration(): void
    {
        $info = $this->buildFunction('Fixtures\Helpers\helperFormat');

        self::assertNotNull($info, 'the function must be built');
        self::assertCount(1, $info->parameters, 'the parsed signature must be carried, not just the name');
        self::assertSame($this->path, $info->file, 'the declaring file must be recorded from the path given');
    }

    public function testReturnsNullWhenTheFileDeclaresNoSuchFunction(): void
    {
        self::assertNull(
            $this->buildFunction('Fixtures\Helpers\notDeclaredHere'),
            'a name the declarations do not carry is absent (RFC 1 §5.3)',
        );
    }

    public function testAClassAskedAsAFunctionDoesNotResolve(): void
    {
        self::assertNull(
            $this->buildFunction('Fixtures\Helpers\HelperRegistry'),
            'the kind must select the declaration list, so one namespace cannot answer for another',
        );
    }

    public function testAFunctionAskedAsAClassDoesNotResolve(): void
    {
        self::assertNull(
            $this->buildClass('Fixtures\Helpers\helperFormat'),
            'the kind must select the declaration list, so one namespace cannot answer for another',
        );
    }

    public function testAConstantAskedAsAClassDoesNotResolve(): void
    {
        self::assertNull(
            $this->buildClass('Fixtures\Helpers\HELPER_LIMIT'),
            'the kind must select the declaration list, so one namespace cannot answer for another',
        );
    }

    public function testClassLookupIsCaseInsensitive(): void
    {
        self::assertNotNull(
            $this->buildClass('FIXTURES\HELPERS\HELPERREGISTRY'),
            'PHP matches class names case-insensitively, which NameKind::normalize owns',
        );
    }

    public function testFunctionLookupIsCaseInsensitive(): void
    {
        self::assertNotNull(
            $this->buildFunction('FIXTURES\HELPERS\HELPERFORMAT'),
            'PHP matches function names case-insensitively, which NameKind::normalize owns',
        );
    }

    public function testConstantsAreBuilt(): void
    {
        self::assertNotSame(
            [],
            $this->declarations->constants,
            'the fixture must declare constants, or this test would pass vacuously',
        );
        self::assertNotNull(
            $this->buildConstant('Fixtures\Helpers\HELPER_LIMIT'),
            'a declared free-standing constant must resolve to ConstantInfo',
        );
    }

    public function testAllClassInfosReportsClassLikesDeclaredInTheFile(): void
    {
        $names = array_map(
            static fn(ClassInfo $info): string => $info->name->qualifiedName->fullyQualifiedName(),
            $this->factory->allClassInfosIn($this->declarations, $this->path),
        );

        self::assertContains(
            'Fixtures\Helpers\HelperRegistry',
            $names,
            'a class-like the file declares must be reported for registration',
        );
    }

    public function testAllFunctionInfosReportsFunctionsDeclaredInTheFile(): void
    {
        $names = array_map(
            static fn($info): string => $info->name->qualifiedName->fullyQualifiedName(),
            $this->factory->allFunctionInfosIn($this->declarations, $this->path),
        );

        self::assertContains(
            'Fixtures\Helpers\helperFormat',
            $names,
            'a function the file declares must be reported, under its own kind',
        );
    }

    public function testAllConstantInfosReportsConstantsDeclaredInTheFile(): void
    {
        $names = array_map(
            static fn($info): string => $info->name->qualifiedName->fullyQualifiedName(),
            $this->factory->allConstantInfosIn($this->declarations, $this->path),
        );

        self::assertContains(
            'Fixtures\Helpers\HELPER_LIMIT',
            $names,
            'a constant the file declares must be reported, under its own kind',
        );
    }

    public function testAllInfosKeepTheFirstOfDuplicateDeclarations(): void
    {
        $content = $this->loadFixture('MultiClass/DuplicateDeclarations.php');
        $document = new TextDocument('file:///dupes.php', 'php', 1, $content);
        $declarations = (new DeclarationScanner())->scan(
            ProductionSyntaxSource::create()->source->parse($document),
        );

        $classNames = array_map(
            static fn(ClassInfo $info): string => $info->name->qualifiedName->fullyQualifiedName(),
            $this->factory->allClassInfosIn($declarations, '/dupes.php'),
        );

        self::assertSame(
            array_unique($classNames),
            $classNames,
            'PHP defines the first declaration of a name, so a second must not register over it',
        );
    }

    public function testExtractsInsteadofExclusions(): void
    {
        $info = $this->buildClassInfoFromFixture(
            'src/Hierarchy/TraitAdaptationUser.php',
            'Fixtures\Hierarchy\TraitAdaptationUser',
        );

        self::assertSame(
            [NameKind::ClassLike->normalize(
                QualifiedName::fromFullyQualified('Fixtures\Hierarchy\ConflictingTraitB'),
            ) => ['conflictMethod']],
            $info->traitExclusions,
            'an insteadof adaptation must record the losing trait under the class-like identity key, '
                . 'so a case-different `use` still matches (RFC 1 §5.6)',
        );
    }

    public function testExtractsAliasAdaptations(): void
    {
        $info = $this->buildClassInfoFromFixture(
            'src/Hierarchy/TraitAdaptationUser.php',
            'Fixtures\Hierarchy\TraitAdaptationUser',
        );

        $aliasesByNewName = [];
        foreach ($info->traitAliases as $alias) {
            $aliasesByNewName[$alias->newName ?? '(visibility-only)'] = $alias;
        }

        self::assertArrayHasKey(
            'conflictMethodFromB',
            $aliasesByNewName,
            'a rename `as` adaptation must record its new name',
        );
        self::assertSame(
            'conflictMethod',
            $aliasesByNewName['conflictMethodFromB']->method,
            'the alias must carry the original method name',
        );
        self::assertNull(
            $aliasesByNewName['conflictMethodFromB']->newVisibility,
            'a rename-only alias does not change visibility',
        );

        self::assertArrayHasKey(
            'protectedOnlyInB',
            $aliasesByNewName,
            'an alias renaming and re-scoping must be recorded',
        );
        self::assertSame(
            Visibility::Protected,
            $aliasesByNewName['protectedOnlyInB']->newVisibility,
            'the new visibility flag must be mapped through visibilityFromFlags',
        );
    }

    public function testPromotedPropertyWithMalformedVarNodeIsSkipped(): void
    {
        // php-parser recovers `private $)` by attaching an Error node as the
        // parameter's var. The extractor must skip it rather than crash on
        // reading a non-string name (RFC 1 §9 tolerance for broken input).
        $info = $this->buildClassInfoFromFixture(
            'src/IncompleteCode/BrokenParameters.php',
            'Fixtures\IncompleteCode\BrokenPromotedProperty',
        );

        self::assertSame(
            [],
            $info->properties,
            'a promoted-property Param whose var is not a Variable must not become a PropertyInfo',
        );
    }

    public function testFunctionParameterWithMalformedVarNodeIsSkipped(): void
    {
        // Same shape as the promoted-property fixture, in a free-standing
        // function's parameter list.
        $path = $this->fixturePath('src/IncompleteCode/BrokenParameters.php');
        $production = ProductionSyntaxSource::create();
        $document = $production->reader->read($path);
        self::assertNotNull($document);
        $declarations = (new DeclarationScanner())->scan($production->source->parse($document));

        $info = $this->factory->functionInfoFrom(
            $declarations,
            new FunctionName(QualifiedName::fromFullyQualified('Fixtures\IncompleteCode\brokenFreeStandingParam')),
            $path,
        );

        self::assertNotNull($info, 'the fixture must declare the free-standing function');
        self::assertSame(
            [],
            $info->parameters,
            'a function Param whose var is not a Variable must not become a ParameterInfo',
        );
    }

    public function testAliasWithoutSourceTraitLeavesTraitNull(): void
    {
        $info = $this->buildClassInfoFromFixture(
            'src/Hierarchy/TraitNamelessAliasUser.php',
            'Fixtures\Hierarchy\TraitNamelessAliasUser',
        );

        self::assertCount(1, $info->traitAliases, 'the fixture declares exactly one alias');
        self::assertNull(
            $info->traitAliases[0]->trait,
            'an `as` clause that names no source trait leaves the alias trait null',
        );
        self::assertSame('onlyInA', $info->traitAliases[0]->method);
        self::assertSame('renamedOnlyInA', $info->traitAliases[0]->newName);
    }

    public function testLookupAgreesWithTheFullScan(): void
    {
        // A derived verb must not fork from the one it derives from.
        foreach ($this->factory->allClassInfosIn($this->declarations, $this->path) as $info) {
            self::assertEquals(
                $info,
                $this->buildClass($info->name->qualifiedName->fullyQualifiedName()),
                'every class the scan reports must be reachable by name, with the same metadata',
            );
        }
        foreach ($this->factory->allFunctionInfosIn($this->declarations, $this->path) as $info) {
            self::assertEquals(
                $info,
                $this->buildFunction($info->name->qualifiedName->fullyQualifiedName()),
                'every function the scan reports must be reachable by name, with the same metadata',
            );
        }
        foreach ($this->factory->allConstantInfosIn($this->declarations, $this->path) as $info) {
            self::assertEquals(
                $info,
                $this->buildConstant($info->name->qualifiedName->fullyQualifiedName()),
                'every constant the scan reports must be reachable by name, with the same metadata',
            );
        }
    }

    private function buildClass(string $fqn): ?ClassInfo
    {
        return $this->factory->classInfoFrom(
            $this->declarations,
            ClasslikeName::fromFullyQualified($fqn),
            $this->path,
        );
    }

    private function buildClassInfoFromFixture(string $fixturePath, string $fqn): ClassInfo
    {
        $path = $this->fixturePath($fixturePath);
        $production = ProductionSyntaxSource::create();
        $document = $production->reader->read($path);
        self::assertNotNull($document, "the fixture $fixturePath must be readable");
        $declarations = (new DeclarationScanner())->scan($production->source->parse($document));

        $info = $this->factory->classInfoFrom(
            $declarations,
            ClasslikeName::fromFullyQualified($fqn),
            $path,
        );
        self::assertNotNull($info, "the fixture must declare $fqn as a class-like");
        return $info;
    }

    private function buildConstant(string $fqn): ?\Firehed\PhpLsp\Domain\ConstantInfo
    {
        return $this->factory->constantInfoFrom(
            $this->declarations,
            new ConstantName(QualifiedName::fromFullyQualified($fqn)),
            $this->path,
        );
    }

    private function buildFunction(string $fqn): ?\Firehed\PhpLsp\Domain\FunctionInfo
    {
        return $this->factory->functionInfoFrom(
            $this->declarations,
            new FunctionName(QualifiedName::fromFullyQualified($fqn)),
            $this->path,
        );
    }
}
