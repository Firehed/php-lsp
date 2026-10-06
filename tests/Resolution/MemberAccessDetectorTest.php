<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Domain\UnionType;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Repository\MemberResolverInterface;
use Firehed\PhpLsp\Resolution\ExpressionResolver;
use Firehed\PhpLsp\Resolution\MemberAccessContext;
use Firehed\PhpLsp\Resolution\MemberAccessDetector;
use Firehed\PhpLsp\Resolution\ResolvedTypeOnly;
use Firehed\PhpLsp\Resolution\TypeSource\NativeTypeSource;
use Firehed\PhpLsp\Resolution\TypeSource\TypeSourceInterface;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpressionResolver::class)]
#[CoversClass(MemberAccessDetector::class)]
#[CoversClass(ResolvedTypeOnly::class)]
class MemberAccessDetectorTest extends TestCase
{
    use LoadsFixturesTrait;

    private const string CHAINS = 'src/Completion/ChainCompletion.php';

    private const string MULTI_CLASS = 'MultiClass/MultiClass.php';

    private const string PROCEDURAL = 'src/Mixed/ProceduralWithClass.php';

    private const string STATIC_ACCESS = 'src/Completion/StaticAccess.php';

    private MemberAccessDetector $detector;
    private SyntaxSourceInterface $parser;

    protected function setUp(): void
    {
        $this->parser = ProductionSyntaxSource::create()->source;

        $emptySource = self::createStub(SymbolSourceInterface::class);
        $emptySource->method('lookupClassLike')->willReturn(null);
        $emptyMemberResolver = new MemberResolver($emptySource);
        $this->detector = new MemberAccessDetector(
            $emptySource,
            $emptyMemberResolver,
            new NativeTypeSource($emptySource, $emptyMemberResolver),
            $this->parser,
        );
    }

    public function testDetectReturnsNullForSelfOutsideClass(): void
    {
        self::assertNull($this->detect('TopLevel/self_outside_class.php', 1, 6));
    }

    public function testDetectReturnsNullForStaticOutsideClass(): void
    {
        self::assertNull($this->detect('TopLevel/static_outside_class.php', 1, 8));
    }

    public function testDetectReturnsNullForParentOutsideClass(): void
    {
        self::assertNull($this->detect('TopLevel/parent_outside_class.php', 1, 8));
    }

    public function testDetectReturnsNullForMemberAccessOnPrimitiveParameter(): void
    {
        $document = new TextDocument(
            'file:///t.php',
            'php',
            1,
            "<?php\nfunction test(string \$s): void {\n    \$s->foo;\n}\n",
        );
        $ast = $this->parser->parse($document);
        // Cursor sits on `foo`.
        self::assertNull(
            $this->detector->detect($document, $ast, 2, 9),
            'A primitive-typed variable has no members and must yield no context',
        );
    }

    public function testDetectResolvesFullyQualifiedClasslikeName(): void
    {
        $result = $this->detect('TopLevel/fully_qualified.php', 2, 18);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('SomeGlobalClass', $result->type->format());
    }

    public function testDetectResolvesPartiallyQualifiedWithAlias(): void
    {
        $result = $this->detect('TopLevel/aliased_partial.php', 4, 16);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('Foo\\Bar\\SubClass', $result->type->format());
    }

    public function testDetectResolvesNestedGroupUse(): void
    {
        $result = $this->detect('TopLevel/nested_group_use.php', 4, 7);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('Vendor\\Package\\Sub\\Thing', $result->type->format());
    }

    public function testDetectResolvesSimpleAliasedUse(): void
    {
        $result = $this->detect('TopLevel/simple_aliased.php', 4, 7);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('Vendor\\Package\\ClassName', $result->type->format());
    }

    public function testDetectResolvesClassInGlobalNamespace(): void
    {
        $result = $this->detect('TopLevel/no_ast.php', 3, 11);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('SomeClass', $result->type->format());
    }

    public function testDetectSlicesPrefixAtByteColumnPastMultibyte(): void
    {
        $content = $this->loadFixture('TopLevel/multibyte_static.php');
        ['line' => $line, 'character' => $character] = $this->locateCursorUtf16($content, 'multibyte_static');

        $result = $this->detect('TopLevel/multibyte_static.php', $line, $character);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('Fixtures\\Domain\\User', $result->type->format());
        self::assertSame(
            'fromArray',
            $result->prefix,
            'slicing the raw wire column would truncate the member prefix (RFC 1 §4.9)',
        );
    }

    public function testDetectResolvesUnimportedClassViaAstNamespace(): void
    {
        $result = $this->detect('TopLevel/namespace_unimported.php', 8, 23);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('App\\Services\\InternalClass', $result->type->format());
    }

    public function testDetectResolvesGlobalNamespaceImport(): void
    {
        $result = $this->detect('TopLevel/global_namespace_use_with_ns.php', 8, 13);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame(
            'GlobalClass',
            $result->type->format(),
            'Global namespace import should resolve to GlobalClass, not App\\GlobalClass',
        );
    }

    public function testDetectResolvesAliasedGroupUse(): void
    {
        $result = $this->detect('TopLevel/aliased_group_use.php', 6, 7);
        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame('Vendor\\Package\\Something', $result->type->format());
    }

    public function testDetectSeesSameClassWhenTargetNameCasingDiffers(): void
    {
        $fixture = 'src/Resolution/CaseInsensitiveIdentity.php';
        $content = $this->loadFixture($fixture);
        ['line' => $line, 'character' => $character] = $this->locateCursor($content, 'case_identity');

        $result = $this->detect($fixture, $line, $character);

        self::assertInstanceOf(MemberAccessContext::class, $result);
        self::assertSame(
            Visibility::Private,
            $result->minVisibility,
            'A vantage that names the target class in different letter case still names the same class',
        );
    }

    /**
     * @return iterable<string, array{string, string, ?MemberAccessContext}>
     */
    public static function receiverCases(): iterable
    {
        $user = self::type('Fixtures\Domain\User');
        $methodAccess = self::type('Fixtures\Completion\MethodAccess');

        yield 'property chain' => [self::CHAINS, 'property_chain', self::instance($user, Visibility::Public)];
        yield 'method chain' => [self::CHAINS, 'method_chain', self::instance($user, Visibility::Public)];
        yield 'nullsafe property chain' => [
            self::CHAINS,
            'nullsafe_property_chain',
            self::instance(new UnionType([$user, new PrimitiveType('null')]), Visibility::Public),
        ];
        yield 'chain across lines' => [
            self::CHAINS,
            'multi_line_chain',
            self::instance(self::type('Fixtures\Completion\Name'), Visibility::Public),
        ];
        yield 'chain from a function' => [
            'src/Completion/FunctionCompletion.php',
            'function_return_chain',
            self::instance(self::type('Fixtures\Completion\Config'), Visibility::Public),
        ];
        yield 'variable from a static call' => [
            self::PROCEDURAL,
            'var_from_static_call',
            self::instance(self::type('Fixtures\Completion\StaticAccess'), Visibility::Public),
        ];
        yield 'from a function' => [
            self::PROCEDURAL,
            'standalone_function_access',
            self::instance($methodAccess, Visibility::Public),
        ];
        yield 'from another class' => [
            'src/Completion/ExternalAccess.php',
            'external_method_access',
            self::instance($methodAccess, Visibility::Public),
        ];
        yield '$this in the second class of a file' => [
            self::MULTI_CLASS,
            'this_in_second_class',
            self::instance(self::type('Fixtures\Completion\ChildInMultiFile'), Visibility::Private),
        ];
        yield '$this beside an unrelated class' => [
            self::MULTI_CLASS,
            'this_in_unrelated_second',
            self::instance(self::type('Fixtures\Completion\SecondUnrelated'), Visibility::Private),
        ];
        yield 'unknown variable' => [self::PROCEDURAL, 'unknown_var', null];
    }

    /**
     * @return iterable<string, array{string, string, ?MemberAccessContext}>
     */
    public static function staticCases(): iterable
    {
        $staticAccess = self::type('Fixtures\Completion\StaticAccess');
        $parentClass = self::type('Fixtures\Inheritance\ParentClass');
        $childClass = self::type('Fixtures\Inheritance\ChildClass');
        $inheritance = 'src/Completion/InheritanceCompletion.php';
        $imports = 'Namespacing/MultiNamespaceImports.php';

        yield 'self::' => [self::STATIC_ACCESS, 'self_empty', self::static($staticAccess, Visibility::Private)];
        yield 'self:: with a prefix' => [
            self::STATIC_ACCESS,
            'self_const_prefix',
            self::static($staticAccess, Visibility::Private, 'NA'),
        ];
        yield 'static::' => [self::STATIC_ACCESS, 'static_keyword', self::static($staticAccess, Visibility::Private)];
        yield 'self:: in a subclass' => [
            $inheritance,
            'self_inherited',
            self::static(self::type('Fixtures\Completion\InheritanceCompletion'), Visibility::Private),
        ];
        yield 'parent::' => [
            $inheritance,
            'parent_access',
            MemberAccessContext::forParent($childClass, Visibility::Protected, ''),
        ];
        yield 'parent:: with a prefix' => [
            $inheritance,
            'parent_prefix',
            MemberAccessContext::forParent($childClass, Visibility::Protected, 'p'),
        ];
        yield 'parent:: without a parent' => ['src/Completion/NoParent.php', 'parent_no_parent', null];
        yield 'an ancestor by name' => [
            $inheritance,
            'parent_class_static',
            self::static($parentClass, Visibility::Protected),
        ];
        yield 'the direct parent by name' => [
            'src/Inheritance/ChildClass.php',
            'direct_parent_static',
            self::static($parentClass, Visibility::Protected),
        ];
        yield 'a grandparent by name' => [
            'src/Inheritance/ChildClass.php',
            'grandparent_access',
            self::static(self::type('Fixtures\Inheritance\Grandparent'), Visibility::Protected),
        ];
        yield 'static access from a function' => [
            self::PROCEDURAL,
            'standalone_static_access',
            self::static($staticAccess, Visibility::Public),
        ];
        yield 'from an anonymous class' => [
            'AnonymousClass.php',
            'static_from_anonymous',
            self::static($staticAccess, Visibility::Public),
        ];
        yield 'self:: in an anonymous class' => ['AnonymousClass.php', 'self_in_anonymous', null];
        yield 'self:: outside a class' => [self::PROCEDURAL, 'self_outside_class', null];
        yield 'a class held in a variable' => [self::PROCEDURAL, 'dynamic_static', null];
        yield 'self:: in the second class of a file' => [
            self::MULTI_CLASS,
            'self_in_second_class',
            self::static(self::type('Fixtures\Completion\SecondUnrelated'), Visibility::Private),
        ];
        yield 'self:: without a namespace' => [
            'NoNamespace.php',
            'self_no_namespace',
            self::static(self::type('NoNamespaceClass'), Visibility::Private),
        ];
        yield 'an imported class' => [
            $imports,
            'imported_static',
            self::static(self::type('Fixtures\Namespacing\Models\User'), Visibility::Public),
        ];
        yield 'an aliased import' => [
            $imports,
            'aliased_static',
            self::static(self::type('Fixtures\Namespacing\Models\UserModel'), Visibility::Public),
        ];
        yield 'an enum' => [
            'src/Completion/EnumUsage.php',
            'unit_enum_prefix',
            self::static(self::type('Fixtures\Enum\Status'), Visibility::Public, 'A'),
        ];
    }

    #[DataProvider('receiverCases')]
    #[DataProvider('staticCases')]
    public function testResolvesTheReceiverAndWhatTheAccessSiteMaySee(
        string $fixture,
        string $marker,
        ?MemberAccessContext $expected,
    ): void {
        $content = $this->loadFixture($fixture);
        ['line' => $line, 'character' => $character] = $this->locateCursor($content, $marker);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $parser = new PhpParserSyntaxSource(new TreeAnnotator());
        $detector = self::detectorKnowingFixtureMembers($parser);

        self::assertEquals(
            $expected,
            $detector->detect($document, $parser->parse($document), $line, $character),
            'the receiver type comes from the expression before the operator; visibility from where the access is',
        );
    }

    /**
     * Members the fixtures declare, so chains and static calls resolve; every
     * other lookup finds nothing.
     */
    private static function detectorKnowingFixtureMembers(SyntaxSourceInterface $parser): MemberAccessDetector
    {
        $user = self::type('Fixtures\Domain\User');
        $methods = [
            'Fixtures\Completion\ChainCompletion::getUser' => $user,
            'Fixtures\Completion\ChainCompletion::getChainableUser' => self::type('Fixtures\Completion\ChainableUser'),
            'Fixtures\Completion\ChainableUser::getName' => self::type('Fixtures\Completion\Name'),
            'Fixtures\Completion\StaticAccess::create' => self::type('Fixtures\Completion\StaticAccess'),
        ];
        $properties = [
            'Fixtures\Completion\ChainCompletion::user' => $user,
            'Fixtures\Completion\ChainCompletion::nullableUser' => new UnionType([$user, new PrimitiveType('null')]),
        ];

        $ancestors = [
            'Fixtures\Completion\InheritanceCompletion' => [
                'Fixtures\Inheritance\ChildClass',
                'Fixtures\Inheritance\ParentClass',
            ],
            'Fixtures\Inheritance\ChildClass' => [
                'Fixtures\Inheritance\ParentClass',
                'Fixtures\Inheritance\Grandparent',
            ],
        ];

        $memberResolver = self::createStub(MemberResolverInterface::class);
        $memberResolver->method('isSubclassOf')->willReturnCallback(
            static fn (ClasslikeName $class, ClasslikeName $parent): bool => in_array(
                $parent->qualifiedName->fullyQualifiedName(),
                $ancestors[$class->qualifiedName->fullyQualifiedName()] ?? [],
                true,
            ),
        );
        $memberResolver->method('findMethod')->willReturnCallback(
            static function (ClasslikeName $class, string $name) use ($methods): ?MethodInfo {
                $type = $methods[self::memberKey($class, $name)] ?? null;
                return $type === null ? null : new MethodInfo(
                    new MethodName($class, $name),
                    Visibility::Public,
                    false,
                    false,
                    false,
                    [],
                    $type,
                    null,
                    null,
                    null,
                );
            },
        );
        $memberResolver->method('findProperty')->willReturnCallback(
            static function (ClasslikeName $class, string $name) use ($properties): ?PropertyInfo {
                $type = $properties[self::memberKey($class, $name)] ?? null;
                return $type === null ? null : new PropertyInfo(
                    new PropertyName($class, $name),
                    Visibility::Private,
                    false,
                    false,
                    false,
                    $type,
                    null,
                    null,
                    null,
                );
            },
        );

        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('lookupFunction')->willReturnCallback(
            static fn (FunctionName $name): ?FunctionInfo => $name->qualifiedName->fullyQualifiedName()
                === 'Fixtures\Completion\getConfig'
                ? new FunctionInfo($name, [], self::type('Fixtures\Completion\Config'), null, null, null)
                : null,
        );

        $methodAccess = self::type('Fixtures\Completion\MethodAccess');
        $types = self::createStub(TypeSourceInterface::class);
        $types->method('forFunctionParameter')->willReturnCallback(
            static fn (FunctionName $function, string $parameter): ?TypeInterface =>
                $function->qualifiedName->fullyQualifiedName() === 'Fixtures\Mixed\processMethodAccess'
                    ? $methodAccess
                    : null,
        );
        $types->method('forMethodParameter')->willReturnCallback(
            static function (MethodName $method, string $parameter) use ($methodAccess): ?TypeInterface {
                $key = self::memberKey($method->owner, $method->name);
                return $key === 'Fixtures\Completion\ExternalAccess::accessMethodAccess' ? $methodAccess : null;
            },
        );

        return new MemberAccessDetector($symbols, $memberResolver, $types, $parser);
    }

    private static function instance(TypeInterface $type, Visibility $visibility): MemberAccessContext
    {
        return MemberAccessContext::forInstance($type, $visibility, '');
    }

    private static function static(
        TypeInterface $type,
        Visibility $visibility,
        string $prefix = '',
    ): MemberAccessContext {
        return MemberAccessContext::forStatic($type, $visibility, $prefix);
    }

    private static function memberKey(ClasslikeName $class, string $name): string
    {
        return $class->qualifiedName->fullyQualifiedName() . '::' . $name;
    }

    private static function type(string $fqn): ClasslikeType
    {
        return new ClasslikeType(ClasslikeName::fromFullyQualified($fqn));
    }

    private function detect(string $fixture, int $line, int $character): ?MemberAccessContext
    {
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $ast = $this->parser->parse($document);
        return $this->detector->detect($document, $ast, $line, $character);
    }
}
