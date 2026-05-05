<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\ResolvedClass;
use Firehed\PhpLsp\Resolution\ResolvedConstant;
use Firehed\PhpLsp\Resolution\ResolvedEnumCase;
use Firehed\PhpLsp\Resolution\ResolvedMethod;
use Firehed\PhpLsp\Resolution\ResolvedProperty;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\ResolvedMember;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use Firehed\PhpLsp\Tests\Handler\OpensDocumentsTrait;
use PHPUnit\Framework\Attributes\CoversClass;
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
        $this->openFixture('src/Domain/User.php');
        $document = $this->documents->get('file:///fixtures/src/Domain/User.php');
        assert($document !== null);

        // Find line with "echo $typed" and position on $typed
        $content = $document->getContent();
        $lines = explode("\n", $content);
        $lineNum = null;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'echo $typed')) {
                $lineNum = $i;
                break;
            }
        }
        assert($lineNum !== null, 'Could not find "echo $typed" in fixture');

        // Position on the $ of $typed (after "echo ")
        $character = strpos($lines[$lineNum], '$typed');
        assert($character !== false);

        $result = $this->resolver->resolveAtPosition($document, $lineNum, $character);

        self::assertInstanceOf(ResolvedVariable::class, $result);
        self::assertStringContainsString('typed', $result->format());
    }

    public function testGetAccessibleMembersReturnsMembers(): void
    {
        $this->openFixture('src/Domain/User.php');

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

        $type = new ClassName('Fixtures\\Domain\\User');
        $members = $this->resolver->getAccessibleMembers($type, Visibility::Public, staticOnly: true);

        self::assertNotEmpty($members);

        // All members should be static
        foreach ($members as $member) {
            self::assertTrue($member->isStatic(), 'Expected only static members');
        }
    }

    public function testGetVariablesInScopeReturnsParameters(): void
    {
        $this->openFixture('src/Domain/User.php');
        $document = $this->documents->get('file:///fixtures/src/Domain/User.php');
        assert($document !== null);

        // Find position inside setName method (has 'name' parameter)
        $content = $document->getContent();
        $lines = explode("\n", $content);
        $lineNum = null;
        foreach ($lines as $i => $line) {
            if (str_contains($line, '$this->name = $name;')) {
                $lineNum = $i;
                break;
            }
        }
        assert($lineNum !== null, 'Could not find line in fixture');

        $variables = $this->resolver->getVariablesInScope($document, $lineNum, 0);

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
}
