<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\NameContextFactory;
use Firehed\PhpLsp\Resolution\ResolvedClass;
use Firehed\PhpLsp\Resolution\ResolvedConstant;
use Firehed\PhpLsp\Resolution\ResolvedEnumCase;
use Firehed\PhpLsp\Resolution\ResolvedFunction;
use Firehed\PhpLsp\Resolution\ResolvedMethod;
use Firehed\PhpLsp\Resolution\ResolvedProperty;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\IntersectionType;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\MemberAccessContext;
use Firehed\PhpLsp\Resolution\MemberAccessKind;
use Firehed\PhpLsp\Resolution\MemberFilter;
use Firehed\PhpLsp\Resolution\ResolvedMember;
use Firehed\PhpLsp\Resolution\ResolvedParameter;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Resolution\TextFallbackHelper;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use Firehed\PhpLsp\Tests\Handler\OpensDocumentsTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Exception;
use Throwable;
use TypeError;

#[CoversClass(SymbolResolver::class)]
#[CoversClass(NameContextFactory::class)]
#[CoversClass(TextFallbackHelper::class)]
final class SymbolResolverTest extends TestCase
{
    use OpensDocumentsTrait;

    private SymbolResolver $resolver;
    private ParserService $parser;
    private DocumentManager $documents;
    private DefaultClassRepository $classRepository;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $this->parser = new ParserService();
        $this->documents = new DocumentManager();
        $classInfoFactory = new DefaultClassInfoFactory();
        $locator = self::createStub(ClassLocator::class);
        $this->classRepository = new DefaultClassRepository(
            $classInfoFactory,
            $locator,
            $this->parser,
        );
        $memberResolver = new MemberResolver($this->classRepository);
        $typeResolver = new BasicTypeResolver($memberResolver);
        $indexer = new DocumentIndexer($this->parser, new SymbolExtractor(), new SymbolIndex());

        $this->resolver = new SymbolResolver(
            parser: $this->parser,
            classRepository: $this->classRepository,
            memberResolver: $memberResolver,
            typeResolver: $typeResolver,
        );

        $this->syncHandler = new TextDocumentSyncHandler(
            $this->documents,
            $this->parser,
            $this->classRepository,
            $classInfoFactory,
            $indexer,
        );
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

    public function testResolvesInstanceMethodCall(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'setName');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(ResolvedMethod::class, $result);
        self::assertStringContainsString('setName', $result->format());
    }

    public function testResolvesNullsafeMethodCall(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'setName_nullsafe');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(ResolvedMethod::class, $result);
        self::assertStringContainsString('setName', $result->format());
    }

    public function testResolvesStaticMethodCall(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'create');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(ResolvedMethod::class, $result);
        self::assertStringContainsString('create', $result->format());
    }

    public function testResolvesClassName(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('SignatureHelp.php', 'class_instantiation');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(ResolvedClass::class, $result);
        self::assertStringContainsString('User', $result->format());
    }

    public function testResolvesFunctionCall(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('SignatureHelp.php', 'signatureHelpAdd');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(ResolvedFunction::class, $result);
        self::assertStringContainsString('signatureHelpAdd', $result->format());
    }

    public function testResolvesBuiltinFunctionCall(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'builtin_strlen');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(ResolvedFunction::class, $result);
        self::assertStringContainsString('strlen', $result->format());
    }

    public function testResolvesPropertyFetch(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'manager');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(ResolvedProperty::class, $result);
        self::assertStringContainsString('manager', $result->format());
    }

    public function testResolvesNullsafePropertyFetch(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'manager_nullsafe');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(ResolvedProperty::class, $result);
        self::assertStringContainsString('manager', $result->format());
    }

    public function testResolvesStaticPropertyFetch(): void
    {
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ParentClass.php', 'staticProperty');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(ResolvedProperty::class, $result);
        self::assertStringContainsString('staticProperty', $result->format());
    }

    public function testResolvesClassConstant(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ParentClass.php', 'class_constant');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(ResolvedConstant::class, $result);
        self::assertStringContainsString('PARENT_CONST', $result->format());
    }

    public function testResolvesEnumCase(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Enum/Status.php', 'enum_case');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(ResolvedEnumCase::class, $result);
        self::assertStringContainsString('Active', $result->format());
    }

    public function testResolvesVariable(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'variable_typed');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(ResolvedVariable::class, $result);
        self::assertStringContainsString('typed', $result->format());
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

        self::assertInstanceOf(ResolvedParameter::class, $result);
        self::assertStringContainsString('name', $result->format());
        self::assertSame('string', $result->getType()?->format());
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

        self::assertInstanceOf(ResolvedParameter::class, $result);
        self::assertStringContainsString('b', $result->format());
        self::assertSame('int', $result->getType()?->format());
    }

    public function testResolvesAttribute(): void
    {
        $this->openFixture('src/Attributes/Route.php');
        $uri = $this->openFixture('src/Services/ApiController.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        // Find the line with the attribute: #[Route('/api/users')]
        $content = $document->getContent();
        $lines = explode("\n", $content);
        $lineNum = 0;
        $character = 0;
        foreach ($lines as $i => $line) {
            if (str_contains($line, '#[Route(')) {
                $lineNum = $i;
                // Position on 'Route' after #[
                $pos = strpos($line, 'Route');
                assert($pos !== false);
                $character = $pos + 2; // Inside the name
                break;
            }
        }

        $result = $this->resolver->resolveAtPosition($document, $lineNum, $character);

        self::assertInstanceOf(ResolvedClass::class, $result);
        self::assertStringContainsString('Route', $result->format());
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

        self::assertInstanceOf(ResolvedParameter::class, $result);
        self::assertStringContainsString('path', $result->format());
        self::assertSame('string', $result->getType()?->format());
    }

    public function testGetAccessibleMembersReturnsMembers(): void
    {
        $uri = $this->openFixture('src/Domain/User.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        // @phpstan-ignore argument.type (test uses fixture class name)
        $type = new ClassName('Fixtures\\Domain\\User');
        $members = $this->resolver->getAccessibleMembers($document, $type, Visibility::Public);

        self::assertNotEmpty($members, 'Should return members for User class');
        // For instance access, should return methods and properties (ResolvedMember)
        foreach ($members as $member) {
            self::assertInstanceOf(ResolvedMember::class, $member);
        }

        self::assertMembersContain($members, 'getName');
    }

    public function testGetAccessibleMembersFiltersStaticOnly(): void
    {
        $uri = $this->openFixture('src/Domain/User.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        // @phpstan-ignore argument.type (test uses fixture class name)
        $type = new ClassName('Fixtures\\Domain\\User');
        $members = $this->resolver->getAccessibleMembers($document, $type, Visibility::Public, MemberFilter::Static);

        self::assertNotEmpty($members, 'Should return static members for User class');

        // All returned symbols for static access should be static members, constants, or enum cases
        foreach ($members as $member) {
            self::assertInstanceOf(ResolvedMember::class, $member);
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

        // @phpstan-ignore argument.type (test uses fixture class name)
        $type = new ClassName('Fixtures\\Enum\\Status');
        $members = $this->resolver->getAccessibleMembers($document, $type, Visibility::Public, MemberFilter::Static);

        $hasEnumCase = false;
        foreach ($members as $member) {
            if ($member instanceof ResolvedEnumCase) {
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
            // @phpstan-ignore argument.type (test uses fixture class name)
            new ClassName('Fixtures\\Domain\\Entity'),
            // @phpstan-ignore argument.type (test uses fixture class name)
            new ClassName('Fixtures\\Domain\\Person'),
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

    public function testGetVariablesInScopeIncludesForeachVariables(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/Variables.php', 'foreach_prefix');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        self::assertVariablesContain($variables, 'item');
    }

    public function testGetVariablesInScopeIncludesForeachKeyVariable(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/Variables.php', 'foreach_key');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        self::assertVariablesContain($variables, 'key', 'value');
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

    public function testGetMemberAccessContextForGlobalVariable(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtCursor('TopLevel/global_scope_completion.php', 'global_member_access');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context);
        self::assertSame(MemberAccessKind::Instance, $context->kind);
        self::assertSame(Visibility::Public, $context->minVisibility, 'Global access is public-only');
        self::assertSame('Fixtures\Domain\User', $context->type->format(), 'Resolves to User type');
    }

    public function testGetMemberAccessContextForGlobalVariableInNamespace(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtCursor('TopLevel/global_scope_completion_ns.php', 'global_member_access_ns');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context);
        self::assertSame(MemberAccessKind::Instance, $context->kind);
        self::assertSame('Fixtures\Domain\User', $context->type->format(), 'Resolves to User type');
    }

    public function testResolvesMemberCallInGlobalScope(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('TopLevel/global_scope_hover.php', 'global_method_call');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(ResolvedMethod::class, $result);
        self::assertStringContainsString('getName', $result->format());
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

    public function testGetCallContextReturnsContext(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Domain/User.php', 'sig_this_call');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context);
        self::assertSame(0, $context->activeParameterIndex);
        self::assertStringContainsString('setName', $context->callable->format());
    }

    public function testGetCallContextReturnsNullOutsideCall(): void
    {
        $this->openFixture('src/Domain/User.php');
        $document = $this->documents->get('file:///fixtures/src/Domain/User.php');
        assert($document !== null);

        // Position at start of file, not in any call
        $context = $this->resolver->getCallContext($document, 0, 0);

        self::assertNull($context);
    }

    public function testGetCallContextTracksActiveParameterIndex(): void
    {
        $cursor = $this->openFixtureAtCursor('SignatureHelp.php', 'second_param');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context);
        self::assertSame(1, $context->activeParameterIndex);
    }

    public function testGetCallContextTracksUsedNamedArgs(): void
    {
        $cursor = $this->openFixtureAtCursor('SignatureHelp.php', 'named_arg');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context);
        self::assertContains('a', $context->usedParameterNames);
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{string, string}>
     */
    public static function callContextNullCases(): iterable
    {
        yield 'undefined function' => ['SignatureHelp.php', 'undefined_func'];
        yield 'dynamic method call' => ['src/Domain/User.php', 'sig_dynamic_method'];
        yield 'dynamic static call' => ['src/Domain/User.php', 'sig_dynamic_static'];
        yield 'dynamic func call' => ['src/Domain/User.php', 'sig_dynamic_func'];
        yield 'dynamic new' => ['src/Domain/User.php', 'sig_dynamic_new'];
        yield 'computed class static' => ['src/Domain/User.php', 'sig_computed_class'];
        yield 'untyped variable' => ['src/Domain/User.php', 'sig_untyped_method'];
        yield 'nonexistent method' => ['src/Domain/User.php', 'sig_nonexistent_method'];
        yield 'nonexistent static method' => ['src/Domain/User.php', 'sig_nonexistent_static'];
        yield 'self outside class' => ['SignatureHelp.php', 'self_outside_class'];
        yield 'new self outside class' => ['SignatureHelp.php', 'new_self_outside'];
        yield 'class without constructor' => ['SignatureHelp.php', 'no_constructor'];
    }

    #[DataProvider('callContextNullCases')]
    public function testGetCallContextReturnsNull(string $fixture, string $cursor): void
    {
        $cursorPos = $this->openFixtureAtCursor($fixture, $cursor);
        $document = $this->documents->get($cursorPos['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursorPos['line'], $cursorPos['character']);

        self::assertNull($context);
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{string, string, class-string<ResolvedMember|ResolvedFunction>, string}>
     */
    public static function callContextResolveCases(): iterable
    {
        yield 'new expression' => [
            'src/Domain/User.php', 'sig_new', ResolvedMethod::class, '__construct',
        ];
        yield 'builtin function' => [
            'src/Domain/User.php', 'sig_builtin_func', ResolvedFunction::class, 'strlen',
        ];
        yield 'user defined function' => [
            'SignatureHelp.php', 'first_param', ResolvedFunction::class, 'signatureHelpAdd',
        ];
    }

    /**
     * @param class-string<ResolvedMember|ResolvedFunction> $expectedType
     */
    #[DataProvider('callContextResolveCases')]
    public function testGetCallContextResolves(
        string $fixture,
        string $cursor,
        string $expectedType,
        string $expectedName,
    ): void {
        $cursorPos = $this->openFixtureAtCursor($fixture, $cursor);
        $document = $this->documents->get($cursorPos['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursorPos['line'], $cursorPos['character']);

        self::assertInstanceOf(CallContext::class, $context);
        self::assertSame(0, $context->activeParameterIndex);
        self::assertInstanceOf($expectedType, $context->callable);
        self::assertStringContainsString($expectedName, $context->callable->format());
    }

    public function testResolveAtPositionResolvesVariableInVariableVariable(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'variable_variable');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        // Hovering on $name in $$name resolves the variable $name
        self::assertInstanceOf(ResolvedVariable::class, $result);
        self::assertStringContainsString('name', $result->format());
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
        self::assertStringContainsString('prop', $result->format());
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

    public function testGetMemberAccessContextForInstanceAccess(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'this_empty');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context);
        self::assertSame(MemberAccessKind::Instance, $context->kind);
        self::assertSame(Visibility::Private, $context->minVisibility);
        self::assertSame('', $context->prefix);
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

    public function testGetMemberAccessContextForNullsafeAccess(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/MethodAccess.php', 'nullsafe_this_empty');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context);
        self::assertSame(MemberAccessKind::Instance, $context->kind);
    }

    public function testGetMemberAccessContextForStaticAccess(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/StaticAccess.php', 'self_empty');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context);
        self::assertSame(MemberAccessKind::Static, $context->kind);
        self::assertSame(Visibility::Private, $context->minVisibility);
    }

    public function testGetMemberAccessContextForParentAccess(): void
    {
        $this->openFixture('src/Inheritance/ParentClass.php');
        $this->openFixture('src/Inheritance/ChildClass.php');
        $cursor = $this->openFixtureAtCursor('src/Completion/InheritanceCompletion.php', 'parent_access');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context);
        self::assertSame(MemberAccessKind::Parent, $context->kind);
        self::assertSame(Visibility::Protected, $context->minVisibility);
    }

    public function testGetMemberAccessContextReturnsNullOutsideMemberAccess(): void
    {
        $this->openFixture('src/Domain/User.php');
        $document = $this->documents->get('file:///fixtures/src/Domain/User.php');
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, 0, 0);

        self::assertNull($context);
    }

    public function testGetMemberAccessContextReturnsNullForNoParent(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NoParent.php', 'parent_no_parent');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

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

    public function testGetMemberAccessContextStaticFromDirectSubclass(): void
    {
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtCursor('src/Inheritance/ChildClass.php', 'direct_parent_static');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context);
        self::assertSame(MemberAccessKind::Static, $context->kind);
        self::assertSame(Visibility::Protected, $context->minVisibility);
    }

    public function testGetMemberAccessContextStaticFromDeepSubclass(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtCursor('src/Inheritance/ChildClass.php', 'grandparent_access');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context);
        self::assertSame(MemberAccessKind::Static, $context->kind);
        self::assertSame(Visibility::Protected, $context->minVisibility);
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

    public function testGetMemberAccessContextReturnsNullForSelfOutsideClass(): void
    {
        $cursor = $this->openFixtureAtCursor('TopLevel/static_access.php', 'toplevel_self');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNull($context);
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

    public function testGetMemberAccessContextStaticFromAnonymousClass(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtCursor('TopLevel/static_access.php', 'anon_class_static');
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
            'var in while' => ['var_access_while', MemberAccessKind::Instance],
            'nullsafe in for' => ['nullsafe_access_for', MemberAccessKind::Instance],
            'static in match' => ['static_access_match', MemberAccessKind::Static],
            'self in switch' => ['self_access_switch', MemberAccessKind::Static],
            'this with prefix in if' => ['this_prefix_if', MemberAccessKind::Instance],
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

    public function testGetMemberAccessContextInIfWithPrefixReturnsPrefix(): void
    {
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/InControlStructures.php', 'this_prefix_if');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context);
        self::assertSame('get', $context->prefix, 'Prefix should be extracted from incomplete member access');
    }

    public function testGetMemberAccessContextForTypedParameterResolvesType(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/InControlStructures.php', 'var_access_while');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context, 'Should resolve typed parameter access');
        self::assertSame(
            'Fixtures\\Domain\\User',
            $context->type->format(),
            'Type should be resolved from parameter type hint',
        );
        self::assertSame(
            Visibility::Public,
            $context->minVisibility,
            'Parameter type is different class, visibility should be public',
        );
    }

    public function testGetMemberAccessContextForPrimitiveParameterReturnsNull(): void
    {
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/InControlStructures.php', 'primitive_param');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNull($context, 'Primitive types have no members, should return null');
    }

    public function testGetMemberAccessContextForNullableParameterResolvesType(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/InControlStructures.php', 'nullable_param');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context, 'Should resolve nullable parameter type');
        self::assertSame(
            'Fixtures\\Domain\\User',
            $context->type->format(),
            'Should resolve underlying type from nullable parameter',
        );
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

    public function testGetMemberAccessContextForAliasedImportResolvesType(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/AliasedImports.php', 'aliased_param');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context, 'Should resolve aliased parameter type');
        self::assertSame(
            'Fixtures\\Domain\\User',
            $context->type->format(),
            'Aliased import should resolve to original FQN',
        );
    }

    public function testGetMemberAccessContextForGroupImportResolvesType(): void
    {
        $this->openFixture('src/Domain/User.php');
        $this->openFixture('src/Domain/Team.php');
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/GroupImports.php', 'group_user_param');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(MemberAccessContext::class, $context, 'Should resolve group import parameter type');
        self::assertSame(
            'Fixtures\\Domain\\User',
            $context->type->format(),
            'Group import should resolve to correct FQN',
        );
    }

    public function testDiagnosticTextBeforeCursor(): void
    {
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/SingleIncomplete.php', 'this_in_if');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $lineText = $document->getLine($cursor['line']);
        $textBeforeCursor = substr($lineText, 0, $cursor['character']);

        // Verify the text before cursor is what we expect
        self::assertStringEndsWith('$this->', $textBeforeCursor, 'Text before cursor should end with $this->');

        // Test the regex pattern
        $pattern = '/\$(\w+)(\?)?->([\w]*)$/';
        $matches = [];
        $result = preg_match($pattern, $textBeforeCursor, $matches);
        self::assertSame(1, $result, "Regex should match the text before cursor: '$textBeforeCursor'");
        self::assertSame('this', $matches[1], 'Should capture variable name');

        // Test text-based class lookup directly
        $content = $document->getContent();
        $lines = explode("\n", $content);

        // Find class declaration
        $foundClass = null;
        $classPattern = '/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|trait|enum)\s+(\w+)/i';
        for ($i = $cursor['line']; $i >= 0; $i--) {
            $lineText = $lines[$i] ?? '';
            if (preg_match($classPattern, $lineText, $m) === 1) {
                $foundClass = $m[1];
                break;
            }
        }
        self::assertSame('SingleIncomplete', $foundClass, 'Should find class name from text');

        // Find namespace
        $foundNamespace = null;
        for ($i = 0; $i < count($lines); $i++) {
            $lineText = $lines[$i];
            if (preg_match('/^\s*namespace\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*[;{]/', $lineText, $m) === 1) {
                $foundNamespace = $m[1];
                break;
            }
        }
        self::assertSame('Fixtures\\IncompleteCode', $foundNamespace, 'Should find namespace from text');

        // Test that getMemberAccessContext returns a valid context
        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);
        self::assertInstanceOf(MemberAccessContext::class, $context, 'Should return context via text-based fallback');
        self::assertSame(MemberAccessKind::Instance, $context->kind);
        self::assertSame('Fixtures\\IncompleteCode\\SingleIncomplete', $context->type->format());
    }

    public function testIsInstantiableReturnsFalseForAbstractClass(): void
    {
        $this->openFixture('src/Utility/ClassModifiers.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertFalse($this->resolver->isInstantiable(new ClassName('Fixtures\\Utility\\AbstractBase')));
    }

    public function testIsInstantiableReturnsTrueForConcreteClass(): void
    {
        $this->openFixture('src/Utility/ClassModifiers.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertTrue($this->resolver->isInstantiable(new ClassName('Fixtures\\Utility\\SealedClass')));
    }

    public function testIsInstantiableReturnsFalseForInterface(): void
    {
        $this->openFixture('src/Domain/Entity.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertFalse($this->resolver->isInstantiable(new ClassName('Fixtures\\Domain\\Entity')));
    }

    public function testIsInstantiableReturnsFalseForEnum(): void
    {
        $this->openFixture('src/Enum/Status.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertFalse($this->resolver->isInstantiable(new ClassName('Fixtures\\Enum\\Status')));
    }

    public function testIsInstantiableReturnsTrueForUnknownClass(): void
    {
        // Unknown classes are assumed instantiable (optimistic filtering)
        /** @phpstan-ignore argument.type (intentionally non-existent) */
        self::assertTrue($this->resolver->isInstantiable(new ClassName('NonExistent\\Unknown')));
    }

    public function testIsValidTypeHintReturnsTrueForClass(): void
    {
        $this->openFixture('src/Domain/User.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertTrue($this->resolver->isValidTypeHint(new ClassName('Fixtures\\Domain\\User')));
    }

    public function testIsValidTypeHintReturnsTrueForInterface(): void
    {
        $this->openFixture('src/Domain/Entity.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertTrue($this->resolver->isValidTypeHint(new ClassName('Fixtures\\Domain\\Entity')));
    }

    public function testIsValidTypeHintReturnsTrueForEnum(): void
    {
        $this->openFixture('src/Enum/Status.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertTrue($this->resolver->isValidTypeHint(new ClassName('Fixtures\\Enum\\Status')));
    }

    public function testIsValidTypeHintReturnsFalseForTrait(): void
    {
        $this->openFixture('src/Traits/SingletonTrait.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertFalse($this->resolver->isValidTypeHint(new ClassName('Fixtures\\Traits\\SingletonTrait')));
    }

    public function testIsValidTypeHintReturnsTrueForUnknownClass(): void
    {
        /** @phpstan-ignore argument.type (intentionally non-existent) */
        self::assertTrue($this->resolver->isValidTypeHint(new ClassName('NonExistent\\Unknown')));
    }

    public function testIsInterfaceReturnsTrueForInterface(): void
    {
        $this->openFixture('src/Domain/Entity.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertTrue($this->resolver->isInterface(new ClassName('Fixtures\\Domain\\Entity')));
    }

    public function testIsInterfaceReturnsFalseForClass(): void
    {
        $this->openFixture('src/Domain/User.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertFalse($this->resolver->isInterface(new ClassName('Fixtures\\Domain\\User')));
    }

    public function testIsInterfaceReturnsFalseForTrait(): void
    {
        $this->openFixture('src/Traits/SingletonTrait.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertFalse($this->resolver->isInterface(new ClassName('Fixtures\\Traits\\SingletonTrait')));
    }

    public function testIsInterfaceReturnsFalseForEnum(): void
    {
        $this->openFixture('src/Enum/Status.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertFalse($this->resolver->isInterface(new ClassName('Fixtures\\Enum\\Status')));
    }

    public function testIsInterfaceReturnsFalseForUnknownClass(): void
    {
        // Unlike the optimistic predicates, an implements list must only offer
        // confirmed interfaces, so an unresolvable name is excluded.
        /** @phpstan-ignore argument.type (intentionally non-existent) */
        self::assertFalse($this->resolver->isInterface(new ClassName('NonExistent\\Unknown')));
    }

    #[DataProvider('extendableClassProvider')]
    public function testIsExtendableClass(?string $fixture, string $fqcn, bool $expected, string $message): void
    {
        if ($fixture !== null) {
            $this->openFixture($fixture);
        }
        /** @phpstan-ignore argument.type (fixture / intentionally non-existent class) */
        self::assertSame($expected, $this->resolver->isExtendableClass(new ClassName($fqcn)), $message);
    }

    /**
     * @return iterable<string, array{?string, string, bool, string}>
     * @codeCoverageIgnore
     */
    public static function extendableClassProvider(): iterable
    {
        yield 'non-final class' => [
            'src/Domain/User.php',
            'Fixtures\\Domain\\User',
            true,
            'A non-final class can be extended',
        ];
        yield 'abstract class' => [
            'src/Utility/ClassModifiers.php',
            'Fixtures\\Utility\\AbstractBase',
            true,
            'An abstract class can be extended',
        ];
        yield 'readonly non-final class' => [
            'src/Utility/ClassModifiers.php',
            'Fixtures\\Utility\\ImmutableClass',
            true,
            'A readonly but non-final class can be extended',
        ];
        yield 'final class' => [
            'src/Utility/ClassModifiers.php',
            'Fixtures\\Utility\\SealedClass',
            false,
            'A final class cannot be extended',
        ];
        yield 'interface' => [
            'src/Domain/Entity.php',
            'Fixtures\\Domain\\Entity',
            false,
            'An interface is not a class and cannot be extended by a class',
        ];
        yield 'trait' => [
            'src/Traits/SingletonTrait.php',
            'Fixtures\\Traits\\SingletonTrait',
            false,
            'A trait cannot be extended by a class',
        ];
        yield 'enum' => [
            'src/Enum/Status.php',
            'Fixtures\\Enum\\Status',
            false,
            'An enum cannot be extended',
        ];
        yield 'unknown class' => [
            null,
            'NonExistent\\Unknown',
            false,
            'An unresolvable name must not be offered as a base class',
        ];
    }

    #[DataProvider('throwableProvider')]
    public function testIsThrowable(?string $fixture, string $fqcn, bool $expected, string $message): void
    {
        if ($fixture !== null) {
            $this->openFixture($fixture);
        }
        /** @phpstan-ignore argument.type (fixture / intentionally non-existent class) */
        self::assertSame($expected, $this->resolver->isThrowable(new ClassName($fqcn)), $message);
    }

    /**
     * @return iterable<string, array{?string, string, bool, string}>
     * @codeCoverageIgnore
     */
    public static function throwableProvider(): iterable
    {
        yield 'Throwable itself' => [
            null,
            Throwable::class,
            true,
            'Throwable is catchable',
        ];
        yield 'built-in exception' => [
            null,
            Exception::class,
            true,
            'A built-in class implementing Throwable is catchable',
        ];
        yield 'built-in error' => [
            null,
            TypeError::class,
            true,
            'Errors are catchable: TypeError transitively implements Throwable',
        ];
        yield 'user class extending a built-in exception' => [
            'src/Exception/AppException.php',
            'Fixtures\\Exception\\AppException',
            true,
            'A class transitively extending a Throwable is catchable',
        ];
        yield 'user interface extending Throwable' => [
            'src/Exception/ExceptionInterface.php',
            'Fixtures\\Exception\\ExceptionInterface',
            true,
            'An interface extending Throwable is catchable',
        ];
        yield 'plain class' => [
            'src/Domain/User.php',
            'Fixtures\\Domain\\User',
            false,
            'A class unrelated to Throwable is not catchable',
        ];
        yield 'plain interface' => [
            'src/Domain/Entity.php',
            'Fixtures\\Domain\\Entity',
            false,
            'An interface unrelated to Throwable is not catchable',
        ];
        yield 'trait' => [
            'src/Traits/SingletonTrait.php',
            'Fixtures\\Traits\\SingletonTrait',
            false,
            'A trait cannot be caught',
        ];
        yield 'enum' => [
            'src/Enum/Status.php',
            'Fixtures\\Enum\\Status',
            false,
            'An enum cannot be caught',
        ];
        yield 'unknown class' => [
            null,
            'NonExistent\\Unknown',
            false,
            'An unresolvable name must not be offered in a catch clause',
        ];
    }

    public function testIsAttributeReturnsTrueForAttributeClass(): void
    {
        $this->openFixture('src/Attributes/Route.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertTrue($this->resolver->isAttribute(new ClassName('Fixtures\\Attributes\\Route')));
    }

    public function testIsAttributeReturnsFalseForPlainClass(): void
    {
        $this->openFixture('src/Domain/User.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertFalse($this->resolver->isAttribute(new ClassName('Fixtures\\Domain\\User')));
    }

    public function testIsAttributeReturnsFalseForInterface(): void
    {
        $this->openFixture('src/Domain/Entity.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertFalse($this->resolver->isAttribute(new ClassName('Fixtures\\Domain\\Entity')));
    }

    public function testIsAttributeReturnsFalseForTrait(): void
    {
        $this->openFixture('src/Traits/SingletonTrait.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertFalse($this->resolver->isAttribute(new ClassName('Fixtures\\Traits\\SingletonTrait')));
    }

    public function testIsAttributeReturnsFalseForEnum(): void
    {
        $this->openFixture('src/Enum/Status.php');
        /** @phpstan-ignore argument.type (fixture class) */
        self::assertFalse($this->resolver->isAttribute(new ClassName('Fixtures\\Enum\\Status')));
    }

    public function testIsAttributeReturnsFalseForUnknownClass(): void
    {
        // Like isInterface, an attribute position must only offer confirmed
        // attributes, so an unresolvable name is excluded.
        /** @phpstan-ignore argument.type (intentionally non-existent) */
        self::assertFalse($this->resolver->isAttribute(new ClassName('NonExistent\\Unknown')));
    }

    public function testGetCallContextForNamedArguments(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'named_empty');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context);
        self::assertStringContainsString('multipleParams', $context->callable->format());
    }

    public function testGetCallContextForAttribute(): void
    {
        $this->openFixture('src/Attributes/Route.php');
        $cursor = $this->openFixtureAtCursor('src/Completion/AttributeNamedArguments.php', 'attr_arg_empty');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context, 'An attribute is a constructor call');
        self::assertStringContainsString('__construct', $context->callable->format());
        self::assertNotNull(
            $context->callable->getParameterByName('path'),
            'The attribute constructor parameters are resolved',
        );
    }

    public function testGetCallContextForIncompleteAttribute(): void
    {
        $this->openFixture('src/Attributes/Route.php');
        $cursor = $this->openFixtureAtCursor(
            'src/Completion/AttributeNamedArgumentsIncomplete.php',
            'attr_arg_incomplete',
        );
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context, 'Call context resolves inside an unclosed #[X(');
        self::assertStringContainsString('__construct', $context->callable->format());
    }

    public function testGetCallContextForIncompleteCode(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/NamedArguments.php', 'incomplete_with_prefix');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context, 'getCallContext should find call in incomplete code');
        self::assertStringContainsString('multipleParams', $context->callable->format());
    }

    public function testGetCallContextWhileEditingInCompleteCall(): void
    {
        // Open the file containing ParamClass so ClassRepository can find it
        $this->openFixture('src/Completion/NamedArguments.php');

        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'editing_in_complete');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context, 'Should detect call context in complete call');
        self::assertStringContainsString('__construct', $context->callable->format());
        self::assertSame(1, $context->positionallyFilledCount, 'First arg is positional');
        self::assertContains('age', $context->usedParameterNames, 'age: is used as named arg');
    }

    public function testGetCallContextWhileEditingBeforeColon(): void
    {
        // Open the file containing ParamClass so ClassRepository can find it
        $this->openFixture('src/Completion/NamedArguments.php');

        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'editing_before_colon');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        // For now, we expect the call context to be found even if positional count is off
        self::assertInstanceOf(CallContext::class, $context, 'Should detect call context when editing before colon');
        self::assertStringContainsString('__construct', $context->callable->format());
    }

    public function testGetCallContextStaticMethodComplete(): void
    {
        $this->openFixture('src/Completion/NamedArguments.php');

        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'static_in_complete');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context, 'Should detect static call context');
        self::assertStringContainsString('staticWithParams', $context->callable->format());
    }

    public function testGetCallContextStaticMethodIncomplete(): void
    {
        $this->openFixture('src/Completion/NamedArguments.php');

        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'static_before_colon');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context, 'Should detect static call context');
        self::assertStringContainsString('staticWithParams', $context->callable->format());
    }

    public function testGetCallContextStaticMethodEmpty(): void
    {
        $this->openFixture('src/Completion/NamedArguments.php');

        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'static_empty');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context, 'Should detect static call context with empty args');
        self::assertStringContainsString('staticWithParams', $context->callable->format());
    }

    public function testGetCallContextInstanceMethodEmpty(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'instance_empty');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context, 'Should detect instance call context with empty args');
        self::assertStringContainsString('instanceMethod', $context->callable->format());
    }

    public function testGetCallContextFunctionCallEmpty(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'function_empty');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context, 'Should detect function call context with empty args');
        self::assertStringContainsString('localHelper', $context->callable->format());
    }

    public function testGetCallContextNullsafeMethodEmpty(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'nullsafe_empty');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context, 'Should detect nullsafe call context');
        self::assertStringContainsString('instanceMethod', $context->callable->format());
    }

    public function testGetCallContextWithNestedBrackets(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'nested_brackets');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context, 'Should handle nested brackets in args');
        self::assertStringContainsString('localHelper', $context->callable->format());
        // Nested parens in first arg + comma means cursor is at second param
        self::assertSame(1, $context->activeParameterIndex, 'Should be on second parameter');
    }

    public function testGetCallContextAfterStatementBoundary(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'after_statement');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertNull($context, 'Should not find call context after statement boundary');
    }

    public function testGetCallContextWithImportedClass(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtCursor('src/Completion/UseStatementCall.php', 'imported_new');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context, 'Should resolve imported class');
        self::assertStringContainsString('__construct', $context->callable->format());
    }

    public function testGetCallContextKeywordParenNotCall(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/CoverageEdgeCases.php', 'keyword_paren');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertNull($context, 'if( should not be detected as function call');
    }

    public function testGetCallContextFullyQualifiedNew(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtCursor('src/Completion/CoverageEdgeCases.php', 'fqn_new');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context, 'Should resolve FQN class');
        self::assertStringContainsString('__construct', $context->callable->format());
    }

    public function testGetCallContextWithNamedArgTracking(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/CoverageEdgeCases.php', 'after_named_arg');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context, 'Should find call context');
        self::assertContains('name', $context->usedParameterNames, 'Should track named arg');
    }

    public function testGetCallContextTopLevelUse(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtCursor('NoNamespace/TopLevelUse.php', 'top_level_use');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        self::assertInstanceOf(CallContext::class, $context, 'Should resolve via top-level use');
        self::assertStringContainsString('__construct', $context->callable->format());
    }

    public function testGetCallContextNoUseNoNamespace(): void
    {
        $cursor = $this->openFixtureAtCursor('NoNamespace/NoUseStatement.php', 'no_use_no_namespace');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        // Class can't be resolved, so context is null
        // This exercises the findNamespaceForLine returning null path
        self::assertNull($context, 'Unresolved class should return null context');
    }

    public function testGetCallContextMultiPartClassName(): void
    {
        $cursor = $this->openFixtureAtCursor('NoNamespace/NoUseStatement.php', 'multi_part_class');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        // Multi-part class name skips use statement resolution
        // Class can't be resolved, so context is null
        self::assertNull($context, 'Unresolved multi-part class should return null context');
    }

    public function testGetCallContextWhitespaceOnlyArg(): void
    {
        $cursor = $this->openFixtureAtCursor('NoNamespace/NoUseStatement.php', 'whitespace_arg');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        // Function can't be resolved, context is null
        // But this exercises the empty arg text path
        self::assertNull($context, 'Unresolved function should return null context');
    }

    public function testGetCallContextThisOutsideClass(): void
    {
        $cursor = $this->openFixtureAtCursor('NoNamespace/NoUseStatement.php', 'this_outside_class');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);

        // $this outside class can't resolve, context is null
        // This exercises the no enclosing class path
        self::assertNull($context, '$this outside class should return null context');
    }

    public function testGetCallContextAfterStringValue(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Completion/EditingNamedArg.php', 'after_string_value');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        // Check that getMemberAccessContext does NOT steal the context
        $memberContext = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);
        self::assertNull($memberContext, 'String value should not be detected as member access');

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);
        self::assertInstanceOf(CallContext::class, $context, 'Should detect call context after string value');
    }

    public function testGetCallContextInIncompleteCode(): void
    {
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/SingleIncompleteSigHelp.php', 'sig_this_call');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getCallContext($document, $cursor['line'], $cursor['character']);
        self::assertInstanceOf(CallContext::class, $context, 'Should detect call context in incomplete code');
    }

    public function testChainedAccessRegexPattern(): void
    {
        // Test that the chain pattern matches correctly
        $text = '        if ($this->user->';
        $pattern = '/(\$\w+(?:\??->[\w]+(?:\([^)]*\))?)+)\??->([\w]*)$/';
        $result = preg_match($pattern, $text, $matches);
        self::assertSame(1, $result, 'Pattern should match chained access');
        self::assertArrayHasKey(1, $matches);
        self::assertArrayHasKey(2, $matches);
        self::assertSame('$this->user', $matches[1], 'Should capture the chain expression');
        self::assertSame('', $matches[2], 'Should capture empty prefix');
    }

    public function testGetMemberAccessContextChainedInIncompleteCode(): void
    {
        // Open User class first so it's available for type resolution
        $this->openFixture('src/Domain/User.php');

        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/ChainedAccess.php', 'chained_in_if');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        // Verify the line text is what we expect
        $lineText = $document->getLine($cursor['line']);
        self::assertStringContainsString('$this->user->', $lineText, 'Line should contain chained access');

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);
        self::assertNotNull($context, 'Chained access in if() should return context');
    }

    // =========================================================================
    // getAccessibleMembersFromText: visibility filtering
    // =========================================================================

    public function testGetAccessibleMembersRespectsConstantVisibility(): void
    {
        $uri = $this->openFixture('src/Repository/ClassInfoPatterns.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        // @phpstan-ignore argument.type (test uses fixture class name)
        $type = new ClassName('Fixtures\\Repository\\ClassInfoPatterns');

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

    public function testGetMemberAccessContextResolvesGroupUseStatic(): void
    {
        // Group use: use Fixtures\Domain\{User, Team};
        // ScopeFinder::resolveFromUseStatements doesn't handle GroupUse,
        // so text-based resolution should handle it.
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/GroupImports.php', 'group_static_access');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNotNull($context);
        self::assertSame('Fixtures\\Domain\\User', $context->type->format());
    }

    public function testGetMemberAccessContextResolvesGroupUseAliased(): void
    {
        // Group use with alias: use Fixtures\Enum\{Status, Priority as Pri};
        $this->openFixture('src/Enum/Priority.php');
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/GroupImports.php', 'group_aliased_static');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNotNull($context);
        self::assertSame('Fixtures\\Enum\\Priority', $context->type->format());
    }

    public function testGetMemberAccessContextResolvesSimpleAliasedUse(): void
    {
        // Simple aliased use: use Fixtures\Domain\User as AliasedUser;
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/AliasedImports.php', 'aliased_static_access');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNotNull($context);
        self::assertSame('Fixtures\\Domain\\User', $context->type->format());
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

    public function testGetMemberAccessContextHandlesParentInIncompleteCode(): void
    {
        // parent:: requires AST to find the extends clause - test that it works
        // even when surrounding code is broken
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/ParentAccess.php', 'parent_incomplete');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNotNull($context, 'parent:: should resolve even in incomplete code');
        self::assertSame('Fixtures\\Inheritance\\ParentClass', $context->type->format());
        self::assertSame(MemberAccessKind::Parent, $context->kind);
    }

    public function testGetMemberAccessContextReturnsNullForParentWithoutExtends(): void
    {
        // parent:: in a class without extends should return null
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/ParentAccess.php', 'parent_no_extends');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNull($context, 'parent:: without extends should return null');
    }

    public function testGetAccessibleMembersExtractsStaticOnlyFromBrokenClass(): void
    {
        // Test that static filter skips property extraction
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/BrokenClassMembers.php', 'broken_static');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);
        self::assertNotNull($context);

        $members = $this->resolver->getAccessibleMembers(
            $document,
            $context->type,
            $context->minVisibility,
            MemberFilter::Static,
        );

        // Should include static members
        self::assertMembersContain($members, 'NAME', 'create');
        // Should NOT include instance properties (Static filter skips them)
        self::assertNotMembersContain($members, 'publicProp');
    }

    public function testGetAccessibleMembersExtractsInstanceMembersFromBrokenClass(): void
    {
        // Test instance member extraction from a broken class
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/BrokenClassMembers.php', 'broken_instance');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);
        self::assertNotNull($context, 'Should detect $this-> in broken class');
        self::assertSame('Fixtures\\IncompleteCode\\BrokenInstanceAccess', $context->type->format());

        $members = $this->resolver->getAccessibleMembers(
            $document,
            $context->type,
            $context->minVisibility,
            MemberFilter::Instance,
        );

        // Should find instance members via text extraction
        self::assertMembersContain($members, 'name', 'test');
    }

    public function testGetMemberAccessContextReturnsNullForThisOutsideClass(): void
    {
        $cursor = $this->openFixtureAtCursor('TopLevel/this_access.php', 'this_toplevel');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNull($context, '$this outside class should return null');
    }

    public function testGetMemberAccessContextReturnsNullForChainedThisOutsideClass(): void
    {
        $cursor = $this->openFixtureAtCursor('TopLevel/this_access.php', 'this_chained_toplevel');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNull($context, 'Chained $this outside class should return null');
    }

    public function testGetMemberAccessContextReturnsNullForChainToUntypedProperty(): void
    {
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/ChainedAccess.php', 'untyped_chain');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNull($context, 'Chain to untyped property should return null');
    }

    public function testGetMemberAccessContextReturnsNullForChainToNonExistentProperty(): void
    {
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/ChainedAccess.php', 'nonexistent_chain');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNull($context, 'Chain to non-existent property should return null');
    }

    public function testGetMemberAccessContextReturnsNullForBrokenSelfAtTopLevel(): void
    {
        // This fixture is severely broken to force text-based fallback
        $cursor = $this->openFixtureAtCursor('TopLevel/broken_self.php', 'broken_self_toplevel');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        self::assertNull($context, 'self:: in broken file at top level should return null');
    }

    public function testGetMemberAccessContextReturnsNullForDoubleArrowInChain(): void
    {
        // Double arrow creates syntax that parser can't recover from
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/ChainedAccess.php', 'double_arrow');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);

        // Double arrow is too broken to recover
        self::assertNull($context);
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

    public function testGetAccessibleMembersIncludesInheritedMembersInTextFallback(): void
    {
        // Open ParentClass so MemberResolver can find it for inheritance
        $this->openFixture('src/Inheritance/ParentClass.php');

        // BrokenChildWithParent has broken syntax (missing opening brace)
        // but extends ParentClass which is resolvable
        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/BrokenInheritance.php', 'broken_inherited');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);
        self::assertNotNull($context, 'Should resolve $this access');

        $members = $this->resolver->getAccessibleMembers(
            $document,
            $context->type,
            $context->minVisibility,
            MemberFilter::Instance,
        );

        // Child member via text fallback, plus inherited members from ParentClass
        self::assertMembersContain($members, 'childMethod', 'parentMethod', 'parentProperty');
    }

    public function testGetAccessibleMembersIncludesInheritedStaticMembersInTextFallback(): void
    {
        $this->openFixture('src/Inheritance/ParentClass.php');

        $cursor = $this->openFixtureAtCursor('src/IncompleteCode/BrokenInheritance.php', 'broken_static_inherited');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getMemberAccessContext($document, $cursor['line'], $cursor['character']);
        self::assertNotNull($context, 'Should resolve self:: access');

        $members = $this->resolver->getAccessibleMembers(
            $document,
            $context->type,
            $context->minVisibility,
            MemberFilter::Static,
        );

        // Child constant via text fallback, plus inherited static members from ParentClass
        self::assertMembersContain($members, 'CHILD_CONST', 'PARENT_CONST', 'staticMethod');
    }

    /**
     * @param list<ResolvedMember> $members
     */
    private static function assertMembersContain(array $members, string ...$expected): void
    {
        $names = self::memberNames($members);
        foreach ($expected as $name) {
            self::assertContains($name, $names, "Member '$name' should be accessible");
        }
    }

    /**
     * @param list<ResolvedMember> $members
     */
    private static function assertNotMembersContain(array $members, string ...$expected): void
    {
        $names = self::memberNames($members);
        foreach ($expected as $name) {
            self::assertNotContains($name, $names, "Member '$name' should not be accessible");
        }
    }

    /**
     * @param list<ResolvedMember> $members
     * @return list<string>
     */
    private static function memberNames(array $members): array
    {
        return array_map(fn(ResolvedMember $m) => $m->getName()->name, $members);
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

    public function testGetNameContextSeparatesImportsByKind(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'imported_class_partial');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getNameContext($document, $cursor['line']);

        self::assertSame(
            'Fixtures\Namespacing\ImportCompletion',
            $context->namespace,
            'The namespace is the one enclosing the cursor',
        );
        self::assertSame(
            'Fixtures\Namespacing\Models\UserRepository',
            $context->classImports['Repo'] ?? null,
            'An aliased class import maps the alias to its FQCN',
        );
        self::assertSame(
            ['Fixtures\Namespacing\Models\makeUser'],
            array_values($context->functionImports),
            'A `use function` import belongs to the function table',
        );
        self::assertSame(
            ['Fixtures\Namespacing\Models\DEFAULT_LIMIT'],
            array_values($context->constantImports),
            'A `use const` import belongs to the constant table',
        );
        self::assertArrayNotHasKey(
            'makeUser',
            $context->classImports,
            'A `use function` import must not leak into the class table',
        );
        self::assertArrayNotHasKey(
            'DEFAULT_LIMIT',
            $context->classImports,
            'A `use const` import must not leak into the class table',
        );
    }

    public function testGetNameContextIsScopedToTheEnclosingNamespaceBlock(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'grouped_import_partial');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getNameContext($document, $cursor['line']);

        self::assertSame(
            'Fixtures\Namespacing\ImportCompletion\Grouped',
            $context->namespace,
            'The namespace is the block containing the cursor, not the first one in the file',
        );
        self::assertArrayHasKey(
            'Post',
            $context->classImports,
            'Group use members should be included',
        );
        self::assertArrayNotHasKey(
            'Repo',
            $context->classImports,
            'Imports from another namespace block are not in scope here',
        );
        self::assertArrayNotHasKey(
            'makeUser',
            $context->functionImports,
            'Function imports from another namespace block are not in scope here',
        );
    }

    public function testGetNameContextSplitsAMixedGroupUseByItemKind(): void
    {
        $cursor = $this->openFixtureAtCursor('Namespacing/ImportCompletion.php', 'mixed_group_partial');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $context = $this->resolver->getNameContext($document, $cursor['line']);

        self::assertSame(
            ['UserRepository' => 'Fixtures\Namespacing\Models\UserRepository'],
            $context->classImports,
            'In a mixed group use, the kind is carried by the item rather than the statement',
        );
        self::assertSame(
            ['makeUser' => 'Fixtures\Namespacing\Models\makeUser'],
            $context->functionImports,
            'A `function` item of a group use belongs to the function table',
        );
        self::assertSame(
            ['DEFAULT_LIMIT' => 'Fixtures\Namespacing\Models\DEFAULT_LIMIT'],
            $context->constantImports,
            'A `const` item of a group use belongs to the constant table',
        );
    }

    public function testGetNameContextInTheGlobalNamespace(): void
    {
        $uri = $this->openFixture('Namespacing/GlobalNamespace.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $context = $this->resolver->getNameContext($document, 0);

        self::assertSame('', $context->namespace, 'A file with no namespace declaration is global');
    }

    public function testGetImportsIncludesAliasedAndGroupedUses(): void
    {
        $uri = $this->openFixture('Namespacing/ImportCompletion.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $imports = $this->resolver->getImports($document);

        self::assertSame(
            'Fixtures\Namespacing\Models\UserRepository',
            $imports['Repo'] ?? null,
            'An aliased import should map the alias to its FQCN',
        );
        self::assertArrayHasKey('Post', $imports, 'Group use members should be included');
        self::assertArrayHasKey('SingletonTrait', $imports, 'Plain imports should be included');
    }

    public function testGetFileFunctionsFindsNamespacedFunctions(): void
    {
        $uri = $this->openFixture('src/Completion/FunctionCompletion.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $names = array_map(
            static fn(FunctionInfo $fn): string => $fn->name,
            $this->resolver->getFileFunctions($document),
        );

        self::assertContains('calculateSum', $names, 'Functions inside a namespace should be found');
        self::assertContains('getConfig', $names, 'Functions inside a namespace should be found');
    }
}
