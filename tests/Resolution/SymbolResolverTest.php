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
use Firehed\PhpLsp\Resolution\ResolvedClass;
use Firehed\PhpLsp\Resolution\ResolvedConstant;
use Firehed\PhpLsp\Resolution\ResolvedEnumCase;
use Firehed\PhpLsp\Resolution\ResolvedFunction;
use Firehed\PhpLsp\Resolution\ResolvedMethod;
use Firehed\PhpLsp\Resolution\ResolvedProperty;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\ResolvedMember;
use Firehed\PhpLsp\Resolution\ResolvedParameter;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use Firehed\PhpLsp\Tests\Handler\OpensDocumentsTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolResolver::class)]
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
        $this->openFixture('src/Domain/User.php');

        // @phpstan-ignore argument.type (test uses fixture class name)
        $type = new ClassName('Fixtures\\Domain\\User');
        $members = $this->resolver->getAccessibleMembers($type, Visibility::Public);

        self::assertNotEmpty($members);
        // For instance access, should return methods and properties (ResolvedMember)
        foreach ($members as $member) {
            self::assertInstanceOf(ResolvedMember::class, $member);
        }

        // Check that public methods are included
        $names = array_map(fn($m) => $m->format(), $members);
        $hasGetName = false;
        foreach ($names as $name) {
            if (str_contains($name, 'getName')) {
                $hasGetName = true;
                break;
            }
        }
        self::assertTrue($hasGetName, 'Expected getName method in accessible members');
    }

    public function testGetAccessibleMembersFiltersStaticOnly(): void
    {
        $this->openFixture('src/Domain/User.php');

        // @phpstan-ignore argument.type (test uses fixture class name)
        $type = new ClassName('Fixtures\\Domain\\User');
        $members = $this->resolver->getAccessibleMembers($type, Visibility::Public, staticOnly: true);

        self::assertNotEmpty($members);

        // All returned symbols for static access should be static members, constants, or enum cases
        foreach ($members as $member) {
            self::assertInstanceOf(ResolvedMember::class, $member);
            self::assertTrue($member->isStatic(), 'Expected only static members');
        }
    }

    public function testGetAccessibleMembersReturnsEmptyForPrimitiveType(): void
    {
        $type = new \Firehed\PhpLsp\Domain\PrimitiveType('string');
        $members = $this->resolver->getAccessibleMembers($type, Visibility::Public);

        self::assertSame([], $members);
    }

    public function testGetAccessibleMembersReturnsEnumCasesForStaticAccess(): void
    {
        $this->openFixture('src/Enum/Status.php');

        // @phpstan-ignore argument.type (test uses fixture class name)
        $type = new ClassName('Fixtures\\Enum\\Status');
        $members = $this->resolver->getAccessibleMembers($type, Visibility::Public, staticOnly: true);

        $hasEnumCase = false;
        foreach ($members as $member) {
            if ($member instanceof ResolvedEnumCase) {
                $hasEnumCase = true;
                break;
            }
        }
        self::assertTrue($hasEnumCase, 'Expected enum cases in static members');
    }

    public function testGetVariablesInScopeReturnsParameters(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Domain/User.php', 'inside_setName');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        self::assertNotEmpty($variables);
        $names = array_map(fn($v) => $v->format(), $variables);
        $hasNameParam = false;
        foreach ($names as $name) {
            if (str_contains($name, '$name')) {
                $hasNameParam = true;
                break;
            }
        }
        self::assertTrue($hasNameParam, 'Expected $name parameter in scope');
    }

    public function testGetVariablesInScopeReturnsEmptyOutsideFunction(): void
    {
        // SignatureHelp.php has file-level code outside any function
        $this->openFixture('SignatureHelp.php');
        $document = $this->documents->get('file:///fixtures/SignatureHelp.php');
        assert($document !== null);

        // Line 0, character 0 is at the very start - outside any function
        $variables = $this->resolver->getVariablesInScope($document, 0, 0);

        self::assertSame([], $variables);
    }

    public function testGetVariablesInScopeIncludesAssignedVariables(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Domain/User.php', 'after_assignment');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        $names = array_map(fn($v) => $v->format(), $variables);
        $hasTyped = false;
        foreach ($names as $name) {
            if (str_contains($name, '$typed')) {
                $hasTyped = true;
                break;
            }
        }
        self::assertTrue($hasTyped, 'Expected $typed variable from assignment');
    }

    public function testGetVariablesInScopeIncludesNestedVariables(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Domain/User.php', 'inside_nested');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        $names = array_map(fn($v) => $v->format(), $variables);
        $hasOuter = false;
        $hasInner = false;
        foreach ($names as $name) {
            if (str_contains($name, '$outer')) {
                $hasOuter = true;
            }
            if (str_contains($name, '$inner')) {
                $hasInner = true;
            }
        }
        self::assertTrue($hasOuter, 'Expected $outer variable');
        self::assertTrue($hasInner, 'Expected $inner variable from nested block');
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
     * @return iterable<string, array{string, string}>
     */
    public static function resolveAtPositionNullCases(): iterable
    {
        yield 'nonexistent static property' => ['src/Domain/User.php', 'dynamic_constant'];
        yield 'unknown class' => ['src/Domain/User.php', 'unknown_class'];
        yield 'unknown property' => ['src/Domain/User.php', 'unknown_property'];
        yield 'unknown constant' => ['src/Domain/User.php', 'unknown_constant'];
        yield 'untyped property' => ['src/Domain/User.php', 'untyped_property'];
        yield 'method definition name' => ['src/Domain/User.php', 'method_name'];
        yield 'property declaration' => ['src/Domain/User.php', 'property_declaration'];
        yield 'self const outside class' => ['SignatureHelp.php', 'self_const_outside'];
        yield 'self prop outside class' => ['SignatureHelp.php', 'self_prop_outside'];
    }

    #[DataProvider('resolveAtPositionNullCases')]
    public function testResolveAtPositionReturnsNull(string $fixture, string $marker): void
    {
        $cursor = $this->openFixtureAtHoverMarker($fixture, $marker);
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        $result = $this->resolver->resolveAtPosition($document, $cursor['line'], $cursor['character']);

        self::assertNull($result);
    }

    public function testResolveNamedArgOnUndefinedFuncReturnsNull(): void
    {
        $uri = $this->openFixture('SignatureHelp.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $content = $document->getContent();
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if (str_contains($line, 'undefinedFunction(badArg:')) {
                $pos = strpos($line, 'badArg');
                assert($pos !== false);

                $result = $this->resolver->resolveAtPosition($document, $lineNum, $pos);

                self::assertNull($result);
                return;
            }
        }
        self::fail('Test fixture line not found');
    }

    public function testResolveNamedArgWithWrongNameReturnsNull(): void
    {
        $uri = $this->openFixture('SignatureHelp.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $content = $document->getContent();
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if (str_contains($line, 'signatureHelpAdd(wrongName:')) {
                // Find 'wrongName:' (with colon) to avoid matching $wrongNamedArg
                $pos = strpos($line, 'wrongName:');
                assert($pos !== false);

                $result = $this->resolver->resolveAtPosition($document, $lineNum, $pos);

                self::assertNull($result);
                return;
            }
        }
        self::fail('Test fixture line not found');
    }

    public function testResolveAttrNamedArgNoConstructorReturnsNull(): void
    {
        $this->openFixture('src/Attributes/NoConstructorAttribute.php');
        $uri = $this->openFixture('src/Services/ApiController.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $content = $document->getContent();
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if (str_contains($line, 'NoConstructorAttribute(someParam:')) {
                $pos = strpos($line, 'someParam');
                assert($pos !== false);

                $result = $this->resolver->resolveAtPosition($document, $lineNum, $pos);

                self::assertNull($result);
                return;
            }
        }
        self::fail('Test fixture line not found');
    }

    public function testResolveAttrNamedArgWrongParamReturnsNull(): void
    {
        $this->openFixture('src/Attributes/Route.php');
        $uri = $this->openFixture('src/Services/ApiController.php');
        $document = $this->documents->get($uri);
        assert($document !== null);

        $content = $document->getContent();
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if (str_contains($line, 'Route(wrongParam:')) {
                $pos = strpos($line, 'wrongParam');
                assert($pos !== false);

                $result = $this->resolver->resolveAtPosition($document, $lineNum, $pos);

                self::assertNull($result);
                return;
            }
        }
        self::fail('Test fixture line not found');
    }

    public function testGetVariablesInScopeSkipsStatementsAfterCursor(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Domain/User.php', 'before_after');
        $document = $this->documents->get($cursor['uri']);
        assert($document !== null);

        // Cursor is before "$after = 3", so $after should not be in scope
        // But $outer and $inner from earlier should be
        $variables = $this->resolver->getVariablesInScope($document, $cursor['line'], $cursor['character']);

        $names = array_map(fn($v) => $v->format(), $variables);
        $hasAfter = false;
        foreach ($names as $name) {
            if (str_contains($name, '$after')) {
                $hasAfter = true;
            }
        }
        self::assertFalse($hasAfter, 'Should not include $after which is assigned after cursor');
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
}
