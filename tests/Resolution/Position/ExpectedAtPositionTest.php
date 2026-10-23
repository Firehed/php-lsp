<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Domain\ClassCandidateFilter;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\KeywordGroup;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\ResolvedCallableInterface;
use Firehed\PhpLsp\Domain\TypeHintContext;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Domain\UnionType;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Repository\MemberResolverInterface;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\Expected;
use Firehed\PhpLsp\Resolution\ExpressionResolver;
use Firehed\PhpLsp\Resolution\MemberAccess;
use Firehed\PhpLsp\Resolution\MemberAccessContext;
use Firehed\PhpLsp\Resolution\MemberAccessKind;
use Firehed\PhpLsp\Resolution\Position\CallAtPositionInterface;
use Firehed\PhpLsp\Resolution\Position\ExpectedAtPosition;
use Firehed\PhpLsp\Resolution\Position\PositionLocator;
use Firehed\PhpLsp\Resolution\TypeSource\NativeTypeSource;
use Firehed\PhpLsp\Resolution\TypeSource\TypeSourceInterface;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type Admits array{
 *   prefix: string,
 *   members: bool,
 *   argumentNames: bool,
 *   variables: bool,
 *   keywords: ?KeywordGroup,
 *   builtinTypes: ?TypeHintContext,
 *   names: ?array{list<NameKind>, ClassCandidateFilter, bool},
 * }
 */
#[CoversClass(ExpectedAtPosition::class)]
final class ExpectedAtPositionTest extends TestCase
{
    use BuildsSymbolInfoTrait;
    use LoadsFixturesTrait;

    private const string CHAINS = 'src/Completion/ChainCompletion.php';

    private const string MULTI_CLASS = 'MultiClass/MultiClass.php';

    private const string PROCEDURAL = 'src/Mixed/ProceduralWithClass.php';

    private const string STATIC_ACCESS = 'src/Completion/StaticAccess.php';

    /**
     * Positions outside any call's argument list.
     *
     * @return iterable<string, array{string, string, Admits}>
     */
    public static function positions(): iterable
    {
        $c = 'src/Completion/';
        $classLikes = static fn (ClassCandidateFilter $filter, bool $absolute = false): array
            => [[NameKind::ClassLike], $filter, $absolute];
        $anyName = [NameKind::cases(), ClassCandidateFilter::Any, false];
        $typeHints = $classLikes(ClassCandidateFilter::TypeHint);

        yield 'a comment' => [$c . 'ContextFiltering.php', 'in_comment', self::admits('')];
        yield 'member access in a comment' => [$c . 'ContextFiltering.php', 'member_in_comment', self::admits('')];
        yield 'text a string interpolates' => [
            'src/Position/Positions.php', 'in_interpolated', self::admits('ite', variables: true),
        ];
        yield 'a variable' => [$c . 'Variables.php', 'local_prefix', self::admits('$l', variables: true)];
        yield 'a declared name' => ['src/Position/Symbols.php', 'declared_method', self::admits('method')];
        yield 'a class body with nothing written' => [$c . 'Keywords.php', 'class_body', self::admits('')];
        yield 'a word in a class body' => [
            'Position/ClassBodyWord.php', 'class_body_word', self::admits('p', keywords: KeywordGroup::ClassBody),
        ];
        yield 'after a member\'s modifiers' => [$c . 'Keywords.php', 'after_visibility', self::admits(
            '',
            keywords: KeywordGroup::AfterVisibility,
            builtinTypes: TypeHintContext::Property,
            names: $typeHints,
        )];
        yield 'a parameter type' => [$c . 'TypeHints.php', 'param_type', self::admits(
            '',
            builtinTypes: TypeHintContext::Parameter,
            names: $typeHints,
        )];
        yield 'a return type' => [$c . 'TypeHints.php', 'return_type', self::admits(
            '',
            builtinTypes: TypeHintContext::ReturnType,
            names: $typeHints,
        )];
        yield 'a property type' => [$c . 'TypeHints.php', 'nullable_property', self::admits(
            '',
            builtinTypes: TypeHintContext::Property,
            names: $typeHints,
        )];
        yield 'after new' => [
            $c . 'NewCompletion.php',
            'new_abstract',
            self::admits('', names: $classLikes(ClassCandidateFilter::Instantiable)),
        ];
        yield 'after instanceof' => [$c . 'InstanceofCompletion.php', 'instanceof_empty', self::admits(
            '',
            names: $typeHints,
        )];
        yield 'a caught type' => [
            $c . 'MultiCatchCompletion.php',
            'catch_multi',
            self::admits('', names: $classLikes(ClassCandidateFilter::Throwable)),
        ];
        yield 'an implements list' => [
            $c . 'ImplementsCompletion.php',
            'implements_empty',
            self::admits('', names: $classLikes(ClassCandidateFilter::Interface_)),
        ];
        yield 'an extended interface' => [
            $c . 'InterfaceExtendsPrefixCompletion.php',
            'interface_extends_d_prefix',
            self::admits('D', names: $classLikes(ClassCandidateFilter::Interface_)),
        ];
        yield 'an extended class' => [
            $c . 'ClassExtendsPrefixCompletion.php',
            'class_extends_p_prefix',
            self::admits('P', names: $classLikes(ClassCandidateFilter::ExtendableClass)),
        ];
        yield 'an attribute' => [
            $c . 'AttributePrefixCompletion.php',
            'attr_prefix',
            self::admits('Rou', names: $classLikes(ClassCandidateFilter::Attribute)),
        ];
        yield 'a used trait' => [
            'Namespacing/TraitUseCompletion.php',
            'trait_use',
            self::admits('HasT', names: $classLikes(ClassCandidateFilter::Trait_)),
        ];
        yield 'what is accessed statically' => [
            'src/Position/Symbols.php',
            'static_receiver',
            self::admits('User', names: $classLikes(ClassCandidateFilter::Any)),
        ];
        yield 'an import, named from the global namespace' => [
            'Namespacing/UseCompletion.php',
            'use_workspace_class',
            self::admits('Fixtures\Domain\\', names: $classLikes(ClassCandidateFilter::Any, true)),
        ];
        yield 'a statement with nothing written' => [$c . 'Keywords.php', 'statement', self::admits('')];
        yield 'a word starting a statement' => [
            'Position/StatementWord.php',
            'statement_word',
            self::admits('fore', keywords: KeywordGroup::All, names: $anyName),
        ];
        yield 'a function being called' => [
            'src/Position/Symbols.php',
            'called',
            self::admits('strlen', keywords: KeywordGroup::All, names: $anyName),
        ];
        yield 'a member' => [$c . 'MethodAccess.php', 'this_prefix', self::admits('get', members: true)];
        yield 'a static member' => [self::STATIC_ACCESS, 'self_prefix', self::admits('get', members: true)];
    }

    /**
     * @param Admits $expected
     */
    #[DataProvider('positions')]
    public function testAPositionAdmitsWhatItsGrammarAllows(string $fixture, string $marker, array $expected): void
    {
        $calls = self::createStub(CallAtPositionInterface::class);

        self::assertSame(
            $expected,
            self::describe($this->expectedAt($fixture, $marker, $calls)),
            'what may be written at the position, and what is written of it so far',
        );
    }

    /**
     * Positions inside a call's argument list.
     *
     * @return iterable<string, array{string, string, bool, Admits}>
     */
    public static function positionsInCalls(): iterable
    {
        $expression = self::admits(
            '',
            argumentNames: true,
            variables: true,
            keywords: KeywordGroup::Expression,
            names: [NameKind::cases(), ClassCandidateFilter::Any, false],
        );
        yield 'an argument' => ['src/Completion/NamedArguments.php', 'named_empty', false, $expression];
        yield 'an argument\'s value' => ['src/Completion/NamedArguments.php', 'after_colon', true, [
            ...$expression,
            'argumentNames' => false,
        ]];
        yield 'a variable' => [
            'src/Completion/EditingNamedArg.php', 'variable_in_call', false, self::admits('$va', variables: true),
        ];
        yield 'an argument\'s name' => [
            'Position/ArgumentNameWord.php', 'argument_name', false, self::admits('stri', argumentNames: true),
        ];
    }

    /**
     * @param Admits $expected
     */
    #[DataProvider('positionsInCalls')]
    public function testAnArgumentAdmitsAnExpressionAndTheNamesOfWhatIsCalled(
        string $fixture,
        string $marker,
        bool $inValue,
        array $expected,
    ): void {
        $calls = self::createStub(CallAtPositionInterface::class);
        $calls->method('callAt')->willReturn(
            new CallContext(self::createStub(ResolvedCallableInterface::class), 0, [], inArgumentValue: $inValue),
        );

        self::assertSame(
            $expected,
            self::describe($this->expectedAt($fixture, $marker, $calls)),
            'an argument holds any expression; a name only until its value is being written',
        );
    }

    public function testTheArgumentNamesAreThoseOfTheCallAtThePosition(): void
    {
        $call = new CallContext(self::createStub(ResolvedCallableInterface::class), 0, []);
        $calls = self::createStub(CallAtPositionInterface::class);
        $calls->method('callAt')->willReturn($call);

        self::assertSame(
            $call,
            $this->expectedAt('src/Completion/NamedArguments.php', 'named_empty', $calls)->argumentNames,
            'the call holding the position is the one whose names are offered',
        );
    }

    public function testAnArgumentOfWhatDoesNotResolveStillAdmitsAnExpression(): void
    {
        self::assertSame(
            self::admits(
                '',
                variables: true,
                keywords: KeywordGroup::Expression,
                names: [NameKind::cases(), ClassCandidateFilter::Any, false],
            ),
            self::describe($this->expectedAt(
                'src/Completion/NamedArguments.php',
                'named_empty',
                self::createStub(CallAtPositionInterface::class),
            )),
            'with nothing known of what is called, its arguments are still expressions, without names',
        );
    }

    public function testAMemberOfWhatDoesNotResolveAdmitsNothing(): void
    {
        self::assertSame(
            self::admits(''),
            self::describe($this->expectedAt(self::PROCEDURAL, 'unknown_var')),
            'a member of what resolves to nothing has no candidates',
        );
    }

    public function testTheNameContextIsTheOneInEffectAtThePosition(): void
    {
        $names = $this->expectedAt('src/Completion/TypeHints.php', 'param_type')->names;

        self::assertNotNull($names, 'a type position admits names');
        self::assertSame('Fixtures\Completion', $names->context->namespace, 'the enclosing namespace');
        self::assertSame(
            ['User' => 'Fixtures\Domain\User', 'Entity' => 'Fixtures\Domain\Entity'],
            $names->context->classImports,
            'the imports in effect there',
        );
    }

    public function testAMemberAccessResolvesNothingBeforeAnyCode(): void
    {
        self::assertNull($this->membersAt('src/Domain/User.php', 0, 0), 'the start of a file is not a member access');
    }

    public function testAStatementWithNothingTypedIsNoMemberAccess(): void
    {
        self::assertNull(
            $this->membersAtMarker('src/Completion/EdgeCases.php', 'not_member_access'),
            'a statement with nothing typed is not a member access',
        );
    }

    /**
     * The statement does not parse, so a `use function` of the same short name
     * must not stand in for the class.
     */
    public function testAClassImportedBesideAFunctionOfTheSameNameIsTheReceiver(): void
    {
        $result = $this->membersAtMarker('src/IncompleteCode/AliasedImports.php', 'colliding_static');
        self::assertNotNull($result, 'the access resolves');
        self::assertSame('Fixtures\Namespacing\Models\Widget', $result->access->on->format());
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function lateBindingOutsideAClass(): iterable
    {
        yield 'self' => ['TopLevel/self_outside_class.php', 1, 6];
        yield 'static' => ['TopLevel/static_outside_class.php', 1, 8];
        yield 'parent' => ['TopLevel/parent_outside_class.php', 1, 8];
    }

    #[DataProvider('lateBindingOutsideAClass')]
    public function testALateBindingKeywordOutsideAClassNamesNothing(string $fixture, int $line, int $character): void
    {
        self::assertNull($this->membersAt($fixture, $line, $character), 'no class is enclosing');
    }

    public function testAPrimitiveHasNoMembers(): void
    {
        $document = new TextDocument(
            'file:///t.php',
            'php',
            1,
            "<?php\nfunction test(string \$s): void {\n    \$s->foo;\n}\n",
        );

        self::assertNull(
            $this->expected()->expectedAt($document, $document->offsetAt(2, 9))->members,
            'A primitive-typed variable has no members and must yield no context',
        );
    }

    /**
     * @return iterable<string, array{string, int, int, string}>
     */
    public static function namedReceivers(): iterable
    {
        yield 'fully qualified' => ['TopLevel/fully_qualified.php', 2, 18, 'SomeGlobalClass'];
        yield 'partly qualified through an alias' => ['TopLevel/aliased_partial.php', 4, 16, 'Foo\\Bar\\SubClass'];
        yield 'from a nested group use' => ['TopLevel/nested_group_use.php', 4, 7, 'Vendor\\Package\\Sub\\Thing'];
        yield 'through an alias' => ['TopLevel/simple_aliased.php', 4, 7, 'Vendor\\Package\\ClassName'];
        yield 'in the global namespace' => ['TopLevel/no_ast.php', 3, 11, 'SomeClass'];
        yield 'in the current namespace' => [
            'TopLevel/namespace_unimported.php', 8, 23, 'App\\Services\\InternalClass',
        ];
        yield 'imported from the global namespace' => [
            'TopLevel/global_namespace_use_with_ns.php', 8, 13, 'GlobalClass',
        ];
        yield 'through an aliased group use' => ['TopLevel/aliased_group_use.php', 6, 7, 'Vendor\\Package\\Something'];
    }

    #[DataProvider('namedReceivers')]
    public function testANamedReceiverResolvesAsWrittenWhereItIsWritten(
        string $fixture,
        int $line,
        int $character,
        string $class,
    ): void {
        self::assertSame(
            $class,
            $this->membersAt($fixture, $line, $character)?->access->on->format(),
            'the name resolves through the namespace and imports in effect',
        );
    }

    public function testAPrefixPastAMultibyteCharacterIsWhatIsWritten(): void
    {
        $content = $this->loadFixture('TopLevel/multibyte_static.php');
        ['line' => $line, 'character' => $character] = $this->locateCursorUtf16($content, 'multibyte_static');

        $result = $this->membersAt('TopLevel/multibyte_static.php', $line, $character);
        self::assertNotNull($result, 'the access resolves');
        self::assertSame('Fixtures\\Domain\\User', $result->access->on->format());
        self::assertSame('fromArray', $result->prefix, 'the position is converted to bytes once');
    }

    /**
     * @return iterable<string, array{string, string, ?MemberAccessContext}>
     */
    public static function receiverCases(): iterable
    {
        $user = self::type('Fixtures\Domain\User');
        $methodAccess = self::type('Fixtures\Completion\MethodAccess');
        $chains = 'Fixtures\Completion\ChainCompletion';

        yield 'property chain' => [self::CHAINS, 'property_chain', self::instance($user, $chains)];
        yield 'method chain' => [self::CHAINS, 'method_chain', self::instance($user, $chains)];
        yield 'nullsafe property chain' => [
            self::CHAINS,
            'nullsafe_property_chain',
            self::instance(new UnionType([$user, new PrimitiveType('null')]), $chains),
        ];
        yield 'chain across lines' => [
            self::CHAINS,
            'multi_line_chain',
            self::instance(self::type('Fixtures\Completion\Name'), $chains),
        ];
        yield 'chain from a function' => [
            'src/Completion/FunctionCompletion.php',
            'function_return_chain',
            self::instance(self::type('Fixtures\Completion\Config'), 'Fixtures\Completion\FunctionCompletionTriggers'),
        ];
        yield 'variable from a static call' => [
            self::PROCEDURAL,
            'var_from_static_call',
            self::instance(self::type('Fixtures\Completion\StaticAccess'), null),
        ];
        yield 'from a function' => [
            self::PROCEDURAL,
            'standalone_function_access',
            self::instance($methodAccess, null),
        ];
        yield 'from another class' => [
            'src/Completion/ExternalAccess.php',
            'external_method_access',
            self::instance($methodAccess, 'Fixtures\Completion\ExternalAccess'),
        ];
        yield '$this in the second class of a file' => [
            self::MULTI_CLASS,
            'this_in_second_class',
            self::instance(self::type('Fixtures\Completion\ChildInMultiFile'), 'Fixtures\Completion\ChildInMultiFile'),
        ];
        yield '$this beside an unrelated class' => [
            self::MULTI_CLASS,
            'this_in_unrelated_second',
            self::instance(self::type('Fixtures\Completion\SecondUnrelated'), 'Fixtures\Completion\SecondUnrelated'),
        ];
        yield 'unknown variable' => [self::PROCEDURAL, 'unknown_var', null];
        yield 'the next statement parsed onto an instance access' => [
            'src/Completion/NextLineParsedOnto.php',
            'this_before_statement',
            self::instance(
                self::type('Fixtures\Completion\NextLineParsedOnto'),
                'Fixtures\Completion\NextLineParsedOnto',
            ),
        ];
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
        $inheritanceClass = 'Fixtures\Completion\InheritanceCompletion';
        $imports = 'Namespacing/MultiNamespaceImports.php';
        $inStaticAccess = 'Fixtures\Completion\StaticAccess';

        yield 'self::' => [self::STATIC_ACCESS, 'self_empty', self::static($staticAccess, $inStaticAccess)];
        yield 'self:: with a prefix' => [
            self::STATIC_ACCESS,
            'self_const_prefix',
            self::static($staticAccess, $inStaticAccess, 'NA'),
        ];
        yield 'static::' => [self::STATIC_ACCESS, 'static_keyword', self::static($staticAccess, $inStaticAccess)];
        yield 'self:: in a subclass' => [
            $inheritance,
            'self_inherited',
            self::static(self::type($inheritanceClass), $inheritanceClass),
        ];
        yield 'parent::' => [
            $inheritance,
            'parent_access',
            self::parent($childClass, $inheritanceClass),
        ];
        yield 'parent:: with a prefix' => [
            $inheritance,
            'parent_prefix',
            self::parent($childClass, $inheritanceClass, 'p'),
        ];
        yield 'parent:: without a parent' => ['src/Completion/NoParent.php', 'parent_no_parent', null];
        yield 'an ancestor by name' => [
            $inheritance,
            'parent_class_static',
            self::static($parentClass, $inheritanceClass),
        ];
        yield 'the direct parent by name' => [
            'src/Inheritance/ChildClass.php',
            'direct_parent_static',
            self::static($parentClass, 'Fixtures\Inheritance\ChildClass'),
        ];
        yield 'a grandparent by name' => [
            'src/Inheritance/ChildClass.php',
            'grandparent_access',
            self::static(self::type('Fixtures\Inheritance\Grandparent'), 'Fixtures\Inheritance\ChildClass'),
        ];
        yield 'static access from a function' => [
            self::PROCEDURAL,
            'standalone_static_access',
            self::static($staticAccess, null),
        ];
        yield 'from an anonymous class' => [
            'AnonymousClass.php',
            'static_from_anonymous',
            self::static($staticAccess, null),
        ];
        yield 'self:: in an anonymous class' => ['AnonymousClass.php', 'self_in_anonymous', null];
        yield 'self:: outside a class' => [self::PROCEDURAL, 'self_outside_class', null];
        yield 'a class held in a variable' => [self::PROCEDURAL, 'dynamic_static', null];
        yield 'an object before ::, which only a class name is resolved before' => [
            self::STATIC_ACCESS,
            'this_static',
            null,
        ];
        yield 'self:: in the second class of a file' => [
            self::MULTI_CLASS,
            'self_in_second_class',
            self::static(self::type('Fixtures\Completion\SecondUnrelated'), 'Fixtures\Completion\SecondUnrelated'),
        ];
        yield 'self:: without a namespace' => [
            'NoNamespace.php',
            'self_no_namespace',
            self::static(self::type('NoNamespaceClass'), 'NoNamespaceClass'),
        ];
        yield 'an imported class' => [
            $imports,
            'imported_static',
            self::static(
                self::type('Fixtures\Namespacing\Models\User'),
                'Fixtures\Namespacing\Controllers\UserController',
            ),
        ];
        yield 'an aliased import' => [
            $imports,
            'aliased_static',
            self::static(
                self::type('Fixtures\Namespacing\Models\UserModel'),
                'Fixtures\Namespacing\Controllers\Aliased\AliasedController',
            ),
        ];
        yield 'the next statement parsed onto a static access' => [
            'TopLevel/static_before_statement.php',
            'static_before_statement',
            self::static(self::type('Fixtures\Domain\User'), null),
        ];
        yield 'an enum' => [
            'src/Completion/EnumUsage.php',
            'unit_enum_prefix',
            self::static(self::type('Fixtures\Enum\Status'), 'Fixtures\Completion\EnumUsage', 'A'),
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
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);

        self::assertEquals(
            $expected,
            self::expectedKnowingFixtureMembers()
                ->expectedAt($document, new ByteOffset($this->markerOffset($content, $marker)))
                ->members,
            'the receiver type comes from the expression before the operator; visibility from where the access is',
        );
    }

    /**
     * @return iterable<string, array{string, string, MemberAccessContext}>
     */
    public static function cursorInsideNameCases(): iterable
    {
        $class = 'Fixtures\Completion\PrefixInsideName';
        $subject = self::type($class);
        yield 'instance property' => [
            'before_instance_property',
            '$this->la',
            self::instance($subject, $class, 'la'),
        ];
        yield 'static property, whose name omits its `$`' => [
            'before_static_property',
            'self::$na',
            self::static($subject, $class, 'na'),
        ];
        yield 'static property, right after its `$`' => [
            'before_static_property',
            'self::$',
            self::static($subject, $class),
        ];
    }

    #[DataProvider('cursorInsideNameCases')]
    public function testPrefixIsTheNameUpToTheCursor(string $marker, string $typed, MemberAccessContext $expected): void
    {
        $fixture = 'src/Completion/PrefixInsideName.php';
        $content = $this->loadFixture($fixture);
        $at = $this->markerOffset($content, $marker) + strlen("/*|{$marker}*/") + strlen($typed);

        self::assertEquals(
            $expected,
            $this->expected()
                ->expectedAt(new TextDocument('file:///' . $fixture, 'php', 1, $content), new ByteOffset($at))
                ->members,
            'only what precedes the cursor has been typed',
        );
    }

    /**
     * @param ?array{list<NameKind>, ClassCandidateFilter, bool} $names
     * @return Admits
     */
    private static function admits(
        string $prefix,
        bool $members = false,
        bool $argumentNames = false,
        bool $variables = false,
        ?KeywordGroup $keywords = null,
        ?TypeHintContext $builtinTypes = null,
        ?array $names = null,
    ): array {
        return [
            'prefix' => $prefix,
            'members' => $members,
            'argumentNames' => $argumentNames,
            'variables' => $variables,
            'keywords' => $keywords,
            'builtinTypes' => $builtinTypes,
            'names' => $names,
        ];
    }

    /**
     * @return Admits
     */
    private static function describe(Expected $expected): array
    {
        $names = $expected->names;
        return self::admits(
            $expected->prefix,
            $expected->members !== null,
            $expected->argumentNames !== null,
            $expected->variables,
            $expected->keywords,
            $expected->builtinTypes,
            $names === null ? null : [$names->kinds, $names->filter, $names->absolute],
        );
    }

    private function expectedAt(string $fixture, string $marker, ?CallAtPositionInterface $calls = null): Expected
    {
        $content = $this->loadFixture($fixture);
        return $this->expected($calls)->expectedAt(
            new TextDocument('file:///' . $fixture, 'php', 1, $content),
            new ByteOffset($this->markerOffset($content, $marker)),
        );
    }

    private function membersAtMarker(string $fixture, string $marker): ?MemberAccessContext
    {
        return $this->expectedAt($fixture, $marker)->members;
    }

    private function membersAt(string $fixture, int $line, int $character): ?MemberAccessContext
    {
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $this->loadFixture($fixture));
        return $this->expected()->expectedAt($document, $document->offsetAt($line, $character))->members;
    }

    /**
     * Over a source that knows nothing of other files.
     */
    private function expected(?CallAtPositionInterface $calls = null): ExpectedAtPosition
    {
        $source = self::createStub(SymbolSourceInterface::class);
        $members = new MemberResolver($source);
        return new ExpectedAtPosition(
            new PositionLocator(ProductionSyntaxSource::create()->source),
            $calls ?? self::createStub(CallAtPositionInterface::class),
            new ExpressionResolver($members, $source, new NativeTypeSource($source, $members)),
        );
    }

    /**
     * Members the fixtures declare, so chains and static calls resolve; every
     * other lookup finds nothing.
     */
    private static function expectedKnowingFixtureMembers(): ExpectedAtPosition
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
        $memberResolver = self::createStub(MemberResolverInterface::class);
        $memberResolver->method('findMethod')->willReturnCallback(
            static function (ClasslikeName $class, string $name) use ($methods): ?MethodInfo {
                $type = $methods[self::memberKey($class, $name)] ?? null;
                return $type === null ? null : self::methodInfo($class, $name, $type);
            },
        );
        $memberResolver->method('findProperty')->willReturnCallback(
            static function (ClasslikeName $class, string $name) use ($properties): ?PropertyInfo {
                $type = $properties[self::memberKey($class, $name)] ?? null;
                return $type === null ? null : self::propertyInfo($class, $name, $type, Visibility::Private);
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

        return new ExpectedAtPosition(
            new PositionLocator(ProductionSyntaxSource::create()->source),
            self::createStub(CallAtPositionInterface::class),
            new ExpressionResolver($memberResolver, $symbols, $types),
        );
    }

    /**
     * @param ?string $from The class-like the access is written in
     */
    private static function instance(TypeInterface $type, ?string $from, string $prefix = ''): MemberAccessContext
    {
        $written = $from === null ? null : self::className($from);
        return new MemberAccessContext(new MemberAccess($type, $written, MemberAccessKind::Instance), $prefix);
    }

    /**
     * @param ?string $from The class-like the access is written in
     */
    private static function static(TypeInterface $type, ?string $from, string $prefix = ''): MemberAccessContext
    {
        $written = $from === null ? null : self::className($from);
        return new MemberAccessContext(new MemberAccess($type, $written, MemberAccessKind::Static), $prefix);
    }

    /**
     * A `parent::` access written in $from, which `static` names.
     */
    private static function parent(TypeInterface $type, string $from, string $prefix = ''): MemberAccessContext
    {
        return new MemberAccessContext(
            new MemberAccess($type, self::className($from), MemberAccessKind::Parent),
            $prefix,
        );
    }

    private static function className(string $fqn): ClasslikeName
    {
        return ClasslikeName::fromFullyQualified($fqn);
    }

    private static function memberKey(ClasslikeName $class, string $name): string
    {
        return $class->qualifiedName->fullyQualifiedName() . '::' . $name;
    }

    private static function type(string $fqn): ClasslikeType
    {
        return new ClasslikeType(ClasslikeName::fromFullyQualified($fqn));
    }
}
