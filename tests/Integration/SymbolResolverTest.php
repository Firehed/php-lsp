<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Integration;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\IntersectionType;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\ResolvedMemberInterface;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\CallContextDetector;
use Firehed\PhpLsp\Resolution\ExpressionResolver;
use Firehed\PhpLsp\Resolution\MemberAccessContext;
use Firehed\PhpLsp\Resolution\MemberAccessDetector;
use Firehed\PhpLsp\Resolution\MemberAccessKind;
use Firehed\PhpLsp\Resolution\NameContextFactory;
use Firehed\PhpLsp\Resolution\ResolvedTypeOnly;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Resolution\TypeSource\NativeTypeSource;
use Firehed\PhpLsp\Tests\BuildsKnowledgeStackTrait;
use Firehed\PhpLsp\Tests\Handler\OpensDocumentsTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CallContextDetector::class)]
#[CoversClass(ExpressionResolver::class)]
#[CoversClass(MemberAccessDetector::class)]
#[CoversClass(NameContextFactory::class)]
#[CoversClass(ResolvedTypeOnly::class)]
#[CoversClass(SymbolResolver::class)]
final class SymbolResolverTest extends TestCase
{
    use BuildsKnowledgeStackTrait;
    use OpensDocumentsTrait;

    private SymbolResolver $resolver;
    private SyntaxSourceInterface $parser;
    private DocumentManager $documents;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $production = ProductionSyntaxSource::create();
        $this->parser = $production->source;
        $this->documents = new DocumentManager();

        $fixturesRoot = dirname(__DIR__) . '/Fixtures';
        $knowledge = $this->knowledgeStackForProjectRoot($fixturesRoot, $production);
        $memberResolver = new MemberResolver($knowledge->source);

        $this->resolver = new SymbolResolver(
            parser: $this->parser,
            locator: $production->locator,
            symbolSource: $knowledge->source,
            memberResolver: $memberResolver,
            typeSource: new NativeTypeSource($knowledge->source, $memberResolver),
        );

        $this->syncHandler = new TextDocumentSyncHandler($this->documents, $knowledge->sink, $knowledge->invalidator);
    }

    public function testResolveAtPositionReturnsNullWhenNoNodeFound(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Domain/User.php', 'sig_this_call');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        // Position way past end of file
        $result = $this->resolver->resolveAtPosition($document, 9999, 0);

        self::assertNull($result);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function resolveNewFallbacks(): iterable
    {
        yield '(new $var)->foo — class node is not a Name' => ['variable_new'];
        yield '(new class {})->foo — class node is Stmt\\Class_' => ['anon_new'];
        yield '(new NoSuchClass)->foo — name resolves but class is not indexed' => ['unknown_new'];
        yield '(new self)->foo at global scope — resolveClasslikeNameInContext returns null'
            => ['global_self_new'];
    }

    #[DataProvider('resolveNewFallbacks')]
    public function testResolveNewFallbacksReturnNull(string $marker): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Hover/ResolveNewFallbacks.php', $marker);
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertNull(
            $result,
            'a method call whose receiver `new` cannot resolve to a class must not resolve',
        );
    }

    public function testResolvesAliasedTraitMethodCallCarriesAliasedFromAndRebindsSelf(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Hover/TraitAliasSelfReturnCall.php', 'aliased_call');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MethodInfo::class, $result);
        self::assertNotNull(
            $result->aliasedFrom,
            'the rebuilt MethodInfo must keep the source-trait identity of the alias',
        );
        self::assertSame(
            'Fixtures\\Hierarchy\\AliasedSelfReturnTrait',
            $result->aliasedFrom->owner->qualifiedName->fullyQualifiedName(),
            'aliasedFrom names the trait that declares fluent()',
        );
        self::assertInstanceOf(
            ClasslikeType::class,
            $result->returnType,
            'self was late-bound to the using class through the aliasedFrom trait subject',
        );
        self::assertSame(
            'Fixtures\\Hover\\TraitAliasSelfReturnCall',
            $result->returnType->name->qualifiedName->fullyQualifiedName(),
            'the trait method\'s self resolves to the using class, not the trait',
        );
    }

    public function testResolvesImportedFunctionCall(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Hover/BuiltinUsage.php', 'imported_function');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(
            FunctionInfo::class,
            $result,
            'A use-function import should resolve via lookupFunction',
        );
        self::assertStringContainsString('helperFormat', $result->format());
    }

    public function testResolvesBuiltinFunctionCall(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'builtin_strlen');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(FunctionInfo::class, $result);
        self::assertStringContainsString('strlen', $result->format());
    }

    public function testResolvesBuiltinConstant(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'builtin_constant');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(
            ConstantInfo::class,
            $result,
            'built-in constants should resolve to ResolvedGlobalConstant',
        );
    }

    public function testResolvesUserDefinedConstant(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'user_constant');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(
            ConstantInfo::class,
            $result,
            'user-defined constants from autoload.files should resolve to ResolvedGlobalConstant',
        );
    }

    public function testResolvesDefineConstant(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'define_constant');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(
            ConstantInfo::class,
            $result,
            'constants defined via define() should resolve to ResolvedGlobalConstant',
        );
        self::assertStringContainsString(
            'FIXTURE_HELPER_DEFINED',
            $result->format(),
            'the resolved constant should format to its declaration',
        );
        self::assertNull(
            $result->getType(),
            'define() constants have no static type information',
        );
    }

    public function testUndefinedConstantReturnsNull(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'undefined_constant');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertNull($result, 'an undefined constant should not resolve');
    }

    public function testResolvesEnumCase(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Enum/Status.php', 'enum_case');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(EnumCaseInfo::class, $result);
        self::assertStringContainsString('Active', $result->format());
    }

    public function testResolvesParameterDeclaration(): void
    {
        $uri = $this->openFixture('src/Domain/User.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        // Find the line with the setName method signature
        $content = $document->getContent();
        $lines = explode("\n", $content);
        $lineNum = 0;
        $character = 0;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'public function setName(string $name)')) {
                $lineNum = $i;
                $pos = strpos($line, '$name');
                assert($pos !== false);
                $character = $pos + 2; // Position inside the variable name
                break;
            }
        }

        $result = $this->resolver->resolveAtPosition($document, $lineNum, $character);

        self::assertInstanceOf(ParameterInfo::class, $result);
        self::assertStringContainsString('name', $result->format());
        self::assertSame('string', $result->getType()?->format());
    }

    public function testResolvesParameterDeclarationWithDefault(): void
    {
        $uri = $this->openFixture('src/Domain/User.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $content = $document->getContent();
        $lines = explode("\n", $content);
        $lineNum = 0;
        $character = 0;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'private int $age = 0')) {
                $lineNum = $i;
                $pos = strpos($line, '$age');
                assert($pos !== false);
                $character = $pos + 2;
                break;
            }
        }

        $result = $this->resolver->resolveAtPosition($document, $lineNum, $character);

        self::assertInstanceOf(ParameterInfo::class, $result);
        self::assertSame('0', $result->defaultValue, 'default is pretty-printed from the AST');
    }

    public function testResolvesFunctionParameterDeclaration(): void
    {
        $uri = $this->openFixture('AutoloadFiles/helpers.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $content = $document->getContent();
        $lines = explode("\n", $content);
        $lineNum = 0;
        $character = 0;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'function helperFormat(string $value)')) {
                $lineNum = $i;
                $pos = strpos($line, '$value');
                assert($pos !== false);
                $character = $pos + 2;
                break;
            }
        }

        $result = $this->resolver->resolveAtPosition($document, $lineNum, $character);

        self::assertInstanceOf(ParameterInfo::class, $result);
        self::assertSame(
            'string',
            $result->getType()?->format(),
            'function-scope param routes through TypeSourceInterface',
        );
    }

    public function testResolvesClosureParameterDeclaration(): void
    {
        $uri = $this->openFixture('src/Resolution/ClosureParameter.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $content = $document->getContent();
        $lines = explode("\n", $content);
        $lineNum = 0;
        $character = 0;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'function (string $captured)')) {
                $lineNum = $i;
                $pos = strpos($line, '$captured');
                assert($pos !== false);
                $character = $pos + 2;
                break;
            }
        }

        $result = $this->resolver->resolveAtPosition($document, $lineNum, $character);

        self::assertInstanceOf(ParameterInfo::class, $result);
        self::assertSame(
            'string',
            $result->getType()?->format(),
            'closure param falls through the shared helper to TypeFactory::fromNode, same as typeOfBinding does',
        );
    }

    public function testResolvesNamedArgument(): void
    {
        $uri = $this->openFixture('SignatureHelp.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        // Find the line with named argument: signatureHelpAdd(a: 1, b: 2)
        $content = $document->getContent();
        $lines = explode("\n", $content);
        $lineNum = 0;
        $character = 0;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'signatureHelpAdd(a: 1, b:')) {
                $lineNum = $i;
                // Position on 'b' in 'b: 2'
                $pos = strpos($line, 'b:');
                assert($pos !== false);
                $character = $pos;
                break;
            }
        }

        $result = $this->resolver->resolveAtPosition($document, $lineNum, $character);

        self::assertInstanceOf(ParameterInfo::class, $result);
        self::assertStringContainsString('b', $result->format());
        self::assertSame('int', $result->getType()?->format());
    }

    public function testResolvesNamedArgumentInAttribute(): void
    {
        $this->openFixture('src/Attributes/Route.php');
        $uri = $this->openFixture('src/Services/ApiController.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        // Find the line with named argument: #[Route(path: '/api/posts', ...)]
        $content = $document->getContent();
        $lines = explode("\n", $content);
        $lineNum = 0;
        $character = 0;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'path:')) {
                $lineNum = $i;
                // Position on 'path' in 'path:'
                $pos = strpos($line, 'path');
                assert($pos !== false);
                $character = $pos;
                break;
            }
        }

        $result = $this->resolver->resolveAtPosition($document, $lineNum, $character);

        self::assertInstanceOf(ParameterInfo::class, $result);
        self::assertStringContainsString('path', $result->format());
        self::assertSame('string', $result->getType()?->format());
    }

    public function testGetAccessibleMembersReturnsMembers(): void
    {
        $uri = $this->openFixture('src/Domain/User.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $type = new ClasslikeType(ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User'));
        $members = $this->resolver->getAccessibleMembers($document, $type, Visibility::Public);

        self::assertNotEmpty($members, 'Should return members for User class');
        // For instance access, should return methods and properties (ResolvedMemberInterface)
        foreach ($members as $member) {
            self::assertInstanceOf(ResolvedMemberInterface::class, $member);
        }

        self::assertMembersContain($members, 'getName');
    }

    public function testGetAccessibleMembersFiltersStaticOnly(): void
    {
        $uri = $this->openFixture('src/Domain/User.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $type = new ClasslikeType(ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User'));
        $members = $this->resolver->getAccessibleMembers($document, $type, Visibility::Public, MemberFilter::Static);

        self::assertNotEmpty($members, 'Should return static members for User class');

        // All returned symbols for static access should be static members, constants, or enum cases
        foreach ($members as $member) {
            self::assertInstanceOf(ResolvedMemberInterface::class, $member);
            self::assertTrue($member->isStatic(), 'Expected only static members');
        }
    }

    public function testGetAccessibleMembersReturnsEmptyForPrimitiveType(): void
    {
        $uri = $this->openFixture('src/Domain/User.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $type = new \Firehed\PhpLsp\Domain\PrimitiveType('string');
        $members = $this->resolver->getAccessibleMembers($document, $type, Visibility::Public);

        self::assertSame([], $members, 'Primitive types should have no accessible members');
    }

    public function testGetAccessibleMembersReturnsEnumCasesForStaticAccess(): void
    {
        $uri = $this->openFixture('src/Enum/Status.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $type = new ClasslikeType(ClasslikeName::fromFullyQualified('Fixtures\\Enum\\Status'));
        $members = $this->resolver->getAccessibleMembers($document, $type, Visibility::Public, MemberFilter::Static);

        $hasEnumCase = false;
        foreach ($members as $member) {
            if ($member instanceof EnumCaseInfo) {
                $hasEnumCase = true;
                break;
            }
        }
        self::assertTrue($hasEnumCase, 'Expected enum cases in static members');
    }

    public function testGetAccessibleMembersUnionsIntersectionConstituents(): void
    {
        $this->openFixture('src/Domain/Entity.php');
        $uri = $this->openFixture('src/Domain/Person.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $type = new IntersectionType([
                new ClasslikeType(ClasslikeName::fromFullyQualified('Fixtures\\Domain\\Entity')),
                new ClasslikeType(ClasslikeName::fromFullyQualified('Fixtures\\Domain\\Person')),
        ]);
        $members = $this->resolver->getAccessibleMembers($document, $type, Visibility::Public);

        // Entity contributes getId(); Person contributes getName() and getAge().
        self::assertMembersContain($members, 'getId', 'getName', 'getAge');
    }

    public function testGetVariablesInScopeReturnsParameters(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Domain/User.php', 'inside_setName');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        self::assertNotEmpty($variables);
        self::assertVariablesContain($variables, 'name');
    }

    public function testGetVariablesInScopeReturnsEmptyBeforeFirstStatement(): void
    {
        $this->openFixture('SignatureHelp.php');
        $document = $this->documents->get('file:///fixtures/SignatureHelp.php');
        assert($document !== null);

        // Line 0, character 0 is at the very start, before any statement that
        // could introduce a variable.
        $variables = $this->resolver->getVariablesInScope($document, 0, 0);

        self::assertSame([], $variables);
    }

    public function testGetVariablesInScopeIncludesAssignedVariables(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Domain/User.php', 'after_assignment');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        self::assertVariablesContain($variables, 'typed');
    }

    public function testGetVariablesInScopeIncludesNestedVariables(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Domain/User.php', 'inside_nested');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        self::assertVariablesContain($variables, 'outer', 'inner');
    }

    public function testGetVariablesInScopeIncludesForeachKeyVariable(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/Variables.php', 'foreach_key');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        self::assertVariablesContain($variables, 'key', 'value');
    }

    public function testGetVariablesInScopeDeduplicatesReassignedVariable(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/Variables.php', 'reassigned_prefix');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        $counterCount = 0;
        foreach ($variables as $variable) {
            if ($variable->getName() === 'counter') {
                $counterCount++;
            }
        }
        self::assertSame(1, $counterCount, 'A reassigned variable is reported once, not once per Assign node');
    }

    public function testGetVariablesInScopeIncludesCatchVariable(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/Variables.php', 'catch_var');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        self::assertVariablesContain($variables, 'ex');
    }

    public function testGetVariablesInScopeIncludesGlobalScopeVariables(): void
    {
        $cursor = $this->openFixtureAtCursor('TopLevel/global_scope_completion.php', 'global_member_access');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        self::assertVariablesContain($variables, 'currentUser', 'loginCount');
    }

    public function testGetVariablesInScopeIncludesGlobalScopeVariablesInNamespace(): void
    {
        $cursor = $this->openFixtureAtCursor('TopLevel/global_scope_completion_ns.php', 'global_member_access_ns');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        self::assertVariablesContain($variables, 'currentUser');
    }

    public function testGetVariablesInScopeExcludesNestedScopeVariables(): void
    {
        $cursor = $this->openFixtureAtCursor('TopLevel/global_scope_nested.php', 'nested_marker');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        self::assertVariablesContain($variables, 'globalOne', 'globalTwo');
        self::assertNotVariablesContain($variables, 'localToFunction', 'localToMethod');
    }

    public function testResolvesGlobalVariableType(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('TopLevel/global_scope_hover.php', 'global_var');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(ResolvedVariable::class, $result);
        self::assertSame('Fixtures\Domain\User', $result->getType()?->format(), 'Resolves to User type');
    }

    public function testResolveAtPositionResolvesVariableInVariableVariable(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'variable_variable');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        // Hovering on $name in $$name resolves the variable $name
        self::assertInstanceOf(ResolvedVariable::class, $result);
        self::assertSame('name', $result->getName(), 'the inner variable of $$name is resolved');
    }

    public function testResolveAtPositionReturnsNullForOuterVariableVariable(): void
    {
        // Cursor positioned at marker, then offset by marker length to land on first $ of $$name
        $cursor = $this->openFixtureAtCursor('src/Domain/User.php', 'outer_var_var');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $markerLength = strlen('/*|outer_var_var*/');
        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character'] + $markerLength);

        // Outer Variable in $$name has non-string name - cannot resolve
        self::assertNull($result);
    }

    public function testResolveAtPositionResolvesVariableInDynamicProperty(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'dynamic_property');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        // Hovering on $prop in $this->$prop resolves the variable $prop
        self::assertInstanceOf(ResolvedVariable::class, $result);
        self::assertSame('prop', $result->getName(), 'the variable naming the property is resolved');
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function resolveAtPositionNullCases(): iterable
    {
        yield 'nonexistent static property' => ['src/Domain/User.php', 'dynamic_constant', []];
        yield 'unknown class' => ['src/Domain/User.php', 'unknown_class', []];
        yield 'unknown property' => ['src/Domain/User.php', 'unknown_property', []];
        yield 'unknown constant' => ['src/Domain/User.php', 'unknown_constant', []];
        yield 'untyped property' => ['src/Domain/User.php', 'untyped_property', []];
        yield 'method definition name' => ['src/Domain/User.php', 'method_name', []];
        yield 'property declaration' => ['src/Domain/User.php', 'property_declaration', []];
        yield 'self const outside class' => ['SignatureHelp.php', 'self_const_outside', []];
        yield 'self prop outside class' => ['SignatureHelp.php', 'self_prop_outside', []];
        yield 'named arg on undefined func' => ['SignatureHelp.php', 'named_arg_undefined_func', []];
        yield 'named arg with wrong name' => ['SignatureHelp.php', 'named_arg_wrong_name', []];
        yield 'attr named arg no constructor' => [
            'src/Services/ApiController.php',
            'attr_no_constructor',
            ['src/Attributes/NoConstructorAttribute.php'],
        ];
        yield 'attr named arg wrong param' => [
            'src/Services/ApiController.php',
            'attr_wrong_param',
            ['src/Attributes/Route.php'],
        ];
    }

    /**
     * @param list<string> $extraFixtures
     */
    #[DataProvider('resolveAtPositionNullCases')]
    public function testResolveAtPositionReturnsNull(string $fixture, string $marker, array $extraFixtures): void
    {
        foreach ($extraFixtures as $extra) {
            $this->openFixture($extra);
        }
        $cursor = $this->openFixtureAtHoverMarker($fixture, $marker);
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertNull($result);
    }

    public function testGetVariablesInScopeSkipsStatementsAfterCursor(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Domain/User.php', 'before_after');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        // Cursor is before "$after = 3", so $after should not be in scope
        // But $outer and $inner from earlier should be
        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        self::assertNotVariablesContain($variables, 'after');
    }

    public function testResolveAtPositionReturnsNullForLiteral(): void
    {
        $this->openFixture('src/Domain/User.php');
        $document = $this->documents->get('file:///fixtures/src/Domain/User.php');
        assert($document !== null);

        $content = $document->getContent();
        $lines = explode("\n", $content);
        $lineNum = null;
        $character = null;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'return 42;') && str_contains($line, 'literal_number')) {
                $lineNum = $i;
                $pos = strpos($line, '42');
                if ($pos !== false) {
                    $character = $pos;
                }
                break;
            }
        }
        assert($lineNum !== null, 'Could not find literal line in fixture');
        assert($character !== null, 'Could not find literal position in fixture');

        $result = $this->resolver->resolveAtPosition($document, $lineNum, $character);

        self::assertNull($result);
    }

    public function testGetMemberAccessContextForInstanceAccessWithPrefix(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'this_prefix');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context);
        self::assertSame(MemberAccessKind::Instance, $context->kind);
        self::assertSame('get', $context->prefix);
    }

    /**
     * Variable member access resolves correctly on a line carrying a multibyte
     * character before the cursor, where the wire column trails the byte column.
     * A regression net over the variable-access surface the wire
     * column feeds.
     */
    public function testGetMemberAccessContextForVariablePastMultibyte(): void
    {
        $cursor = $this->openFixtureAtUtf16Cursor(
            'src/IncompleteCode/MultibyteVarAccess.php',
            'var_member_multibyte',
        );
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context);
        self::assertSame('Fixtures\\IncompleteCode\\MultibyteVarAccess', $context->type->format());
        self::assertSame('', $context->prefix);
    }

    /**
     * The same wire-column-past-multibyte scenario, but through the variable
     * access text fallback: an incomplete `while ($user->` denies a clean AST
     * node, so resolution slices the text before the cursor itself. A raw
     * wire-column slice would truncate past the "🎉" and resolve nothing.
     */
    public function testGetMemberAccessContextForVariableViaFallbackPastMultibyte(): void
    {
        $cursor = $this->openFixtureAtUtf16Cursor(
            'src/IncompleteCode/MultibyteVarAccess.php',
            'var_while_multibyte',
        );
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context);
        self::assertSame('Fixtures\\Domain\\User', $context->type->format());
        self::assertSame('', $context->prefix);
    }

    public function testGetMemberAccessContextReturnsNullOutsideMemberAccess(): void
    {
        $this->openFixture('src/Domain/User.php');
        $document = $this->documents->get('file:///fixtures/src/Domain/User.php');
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, 0, 0);

        self::assertNull($context);
    }

    public function testGetMemberAccessContextReturnsNullForUnresolvedVariable(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EdgeCases.php', 'unresolved_var');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNull($context);
    }

    public function testGetMemberAccessContextReturnsNullForDynamicClass(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EdgeCases.php', 'dynamic_class');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNull($context);
    }

    public function testGetMemberAccessContextStaticFromUnrelatedClass(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtCursor('src/Completion/EdgeCases.php', 'unrelated_static');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context);
        self::assertSame(MemberAccessKind::Static, $context->kind);
        self::assertSame(Visibility::Public, $context->minVisibility);
    }

    public function testGetMemberAccessContextReturnsNullForNonMemberAccess(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EdgeCases.php', 'not_member_access');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNull($context);
    }

    public function testGetMemberAccessContextStaticFromTopLevel(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtCursor('TopLevel/static_access.php', 'toplevel_static');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context);
        self::assertSame(MemberAccessKind::Static, $context->kind);
        self::assertSame(Visibility::Public, $context->minVisibility);
    }

    public function testGetMemberAccessContextReturnsNullInsideMethodArgs(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/CoverageEdgeCases.php', 'inside_method_args');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNull($context, 'Should return null when cursor is inside method arguments');
    }

    public function testGetMemberAccessContextReturnsNullInsideStaticArgs(): void
    {
        $this->openFixture('src/Completion/NamedArguments.php');
        $cursor = $this->openFixtureAtCursor('src/Completion/CoverageEdgeCases.php', 'inside_static_args');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNull($context, 'Should return null when cursor is inside static call arguments');
    }

    // =========================================================================
    // getMemberAccessContext: Incomplete code in control structures
    // These test text-based fallback when AST detection fails
    // =========================================================================

    /**
     * @return array<string, array{string, MemberAccessKind}>
     * @codeCoverageIgnore
     */
    public static function incompleteCodeMemberAccessProvider(): array
    {
        return [
            'this in if' => ['this_access_if', MemberAccessKind::Instance],
            'nullsafe in for' => ['nullsafe_access_for', MemberAccessKind::Instance],
            'static in match' => ['static_access_match', MemberAccessKind::Static],
            'self in switch' => ['self_access_switch', MemberAccessKind::Static],
            'in return' => ['in_return', MemberAccessKind::Instance],
            'in assignment' => ['in_assignment', MemberAccessKind::Instance],
            'in array' => ['in_array', MemberAccessKind::Instance],
            'in call arg' => ['in_call_arg', MemberAccessKind::Instance],
            'in ternary' => ['in_ternary', MemberAccessKind::Instance],
        ];
    }

    #[DataProvider('incompleteCodeMemberAccessProvider')]
    public function testGetMemberAccessContextInIncompleteCode(string $marker, MemberAccessKind $expectedKind): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/InControlStructures.php', $marker);
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(
            MemberAccessContext::class,
            $context,
            "getMemberAccessContext should return context for marker '$marker'",
        );
        self::assertSame($expectedKind, $context->kind, "Access kind should match for marker '$marker'");
    }

    public function testGetMemberAccessContextForIntersectionParameterResolvesType(): void
    {
        $this->openFixture('src/Domain/Entity.php');
        $this->openFixture('src/Domain/Person.php');
        $cursor = $this->openFixtureAtCursor('src/Completion/IntersectionAccess.php', 'intersection_access');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context, 'Should resolve intersection parameter access');
        self::assertSame(
            'Fixtures\\Domain\\Entity&Fixtures\\Domain\\Person',
            $context->type->format(),
            'Type should be resolved from intersection parameter type hint',
        );
        self::assertSame(MemberAccessKind::Instance, $context->kind, 'Access via -> is instance access');

        $members = $this->resolver->getAccessibleMembers(
            $document,
            $context->type,
            $context->minVisibility,
            MemberFilter::Instance,
        );
        // Entity contributes getId(); Person contributes getName() and getAge().
        self::assertMembersContain($members, 'getId', 'getName', 'getAge');
    }

    // =========================================================================
    // getAccessibleMembersFromText: visibility filtering
    // =========================================================================

    public function testGetAccessibleMembersRespectsConstantVisibility(): void
    {
        $uri = $this->openFixture('src/Repository/ClassInfoPatterns.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $type = new ClasslikeType(ClasslikeName::fromFullyQualified('Fixtures\\Repository\\ClassInfoPatterns'));

        // When accessed from outside (Public visibility), only public constants should be visible
        $members = $this->resolver->getAccessibleMembers(
            $document,
            $type,
            Visibility::Public,
            MemberFilter::Static,
        );

        self::assertMembersContain($members, 'PUBLIC_CONST');
        self::assertNotMembersContain($members, 'PROTECTED_CONST', 'PRIVATE_CONST');
    }

    public function testGetMemberAccessContextKeepsClassAndFunctionImportsApart(): void
    {
        // The file imports both Models\Widget (a class) and Helpers\Widget (a
        // function). PHP resolves each from its own table, so the class import
        // is what `Widget::` names — including on the text fallback path, which
        // this unrecoverable statement forces.
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/AliasedImports.php', 'colliding_static');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNotNull($context);
        self::assertSame(
            'Fixtures\\Namespacing\\Models\\Widget',
            $context->type->format(),
            'A `use function` of the same short name must not shadow the class import',
        );
    }

    public function testGetAccessibleMembersUsesTextFallbackForBrokenClass(): void
    {
        // VeryBrokenTarget is missing its opening brace - parser can't recognize it as a class.
        // But text-based detection finds `VeryBrokenTarget::` and extractMembers can
        // find the const and static method via regex.
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/BrokenClassMembers.php', 'broken_static');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);
        self::assertNotNull($context, 'Should detect static access on broken class');
        self::assertSame('Fixtures\\IncompleteCode\\VeryBrokenTarget', $context->type->format());

        $members = $this->resolver->getAccessibleMembers(
            $document,
            $context->type,
            $context->minVisibility,
            MemberFilter::All,
        );

        // Text-based extraction should find these even though class has broken syntax
        self::assertMembersContain($members, 'NAME', 'create', 'publicProp');
        // Private members should be filtered out when accessed externally
        self::assertNotMembersContain($members, 'SECRET', 'privateHelper', 'privateProp');
    }

    public function testGetMemberAccessContextResolvesClassInGlobalNamespace(): void
    {
        // No namespace, no use statements - class name should be raw
        $cursor = $this->openFixtureAtCursor('TopLevel/no_ast.php', 'empty_ast_static');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        // Should resolve to raw class name
        self::assertNotNull($context);
        self::assertSame('SomeClass', $context->type->format());
    }

    /**
     * @param list<ResolvedMemberInterface> $members
     */
    private static function assertMembersContain(array $members, string ...$expected): void
    {
        $names = self::memberNames($members);
        foreach ($expected as $name) {
            self::assertContains($name, $names, "Member '$name' should be accessible");
        }
    }

    /**
     * @param list<ResolvedMemberInterface> $members
     */
    private static function assertNotMembersContain(array $members, string ...$expected): void
    {
        $names = self::memberNames($members);
        foreach ($expected as $name) {
            self::assertNotContains($name, $names, "Member '$name' should not be accessible");
        }
    }

    /**
     * @param list<ResolvedMemberInterface> $members
     * @return list<string>
     */
    private static function memberNames(array $members): array
    {
        return array_map(fn(ResolvedMemberInterface $m) => $m->getName()->name, $members);
    }

    /**
     * @param list<ResolvedVariable> $variables
     */
    private static function assertVariablesContain(array $variables, string ...$expected): void
    {
        $names = self::variableNames($variables);
        foreach ($expected as $name) {
            self::assertContains($name, $names, "Variable \$$name should be in scope");
        }
    }

    /**
     * @param list<ResolvedVariable> $variables
     */
    private static function assertNotVariablesContain(array $variables, string ...$expected): void
    {
        $names = self::variableNames($variables);
        foreach ($expected as $name) {
            self::assertNotContains($name, $names, "Variable \$$name should not be in scope");
        }
    }

    /**
     * @param list<ResolvedVariable> $variables
     * @return list<string>
     */
    private static function variableNames(array $variables): array
    {
        return array_map(fn(ResolvedVariable $v) => $v->getName(), $variables);
    }
}
