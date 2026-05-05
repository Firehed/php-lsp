<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\HoverHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use Firehed\PhpLsp\Utility\MemberAccessResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HoverHandler::class)]
class HoverHandlerTest extends TestCase
{
    use OpensDocumentsTrait;

    private DocumentManager $documents;
    private ParserService $parser;
    private DefaultClassRepository $classRepository;
    private DefaultClassInfoFactory $classInfoFactory;
    private MemberResolver $memberResolver;
    private HoverHandler $handler;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager();
        $this->parser = new ParserService();
        $this->classInfoFactory = new DefaultClassInfoFactory();
        $locator = self::createStub(ClassLocator::class);
        $this->classRepository = new DefaultClassRepository(
            $this->classInfoFactory,
            $locator,
            $this->parser,
        );
        $this->memberResolver = new MemberResolver($this->classRepository);
        $typeResolver = new BasicTypeResolver($this->memberResolver);
        $this->handler = new HoverHandler(
            $this->documents,
            $this->parser,
            $this->classRepository,
            $this->memberResolver,
            new MemberAccessResolver($typeResolver),
        );
        $indexer = new DocumentIndexer($this->parser, new SymbolExtractor(), new SymbolIndex());
        $this->syncHandler = new TextDocumentSyncHandler(
            $this->documents,
            $this->parser,
            $this->classRepository,
            $this->classInfoFactory,
            $indexer,
        );
    }

    public function testSupports(): void
    {
        self::assertTrue($this->handler->supports('textDocument/hover'));
        self::assertFalse($this->handler->supports('textDocument/definition'));
    }

    public function testHoverOnClassWithDocblock(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('SignatureHelp.php', 'class_instantiation');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('User', $result['contents']);
        self::assertStringContainsString('Represents a system user', $result['contents']);
    }

    public function testHoverReturnsNullForUnknownPosition(): void
    {
        $code = '<?php // just a comment';
        $this->openDocument('file:///test.php', $code);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/hover',
            'params' => [
                'textDocument' => ['uri' => 'file:///test.php'],
                'position' => ['line' => 0, 'character' => 10],
            ],
        ]);

        $result = $this->handler->handle($request);

        self::assertNull($result);
    }

    public function testHoverOnFunction(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('SignatureHelp.php', 'signatureHelpAdd');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('signatureHelpAdd', $result['contents']);
        self::assertStringContainsString('int', $result['contents']);
        self::assertStringContainsString('Adds two numbers together', $result['contents']);
    }

    public function testHoverOnMethod(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'setName');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['contents']);
        self::assertStringContainsString('Updates the user', $result['contents']);
    }

    public function testHoverOnProperty(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'manager');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$manager', $result['contents']);
        self::assertStringContainsString('User', $result['contents']);
    }

    public function testHoverOnBuiltinFunction(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Hover/BuiltinUsage.php', 'builtin_function');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('sort', $result['contents']);
    }

    public function testHoverOnStaticMethod(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'create');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('create', $result['contents']);
    }

    public function testHoverOnTypedVariableMethodCall(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('SignatureHelp.php', 'typedVarMethod');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['contents']);
        self::assertStringContainsString('Updates the user', $result['contents']);
    }

    public function testHoverOnAssignedVariableMethodCall(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('SignatureHelp.php', 'assignedVarMethod');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['contents']);
        self::assertStringContainsString('Updates the user', $result['contents']);
    }

    public function testHoverOnInheritedMethod(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'inherited_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('parentMethod', $result['contents']);
        self::assertStringContainsString('Parent method documentation', $result['contents']);
    }

    public function testHoverOnInheritedProperty(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'inherited_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$parentProperty', $result['contents']);
        self::assertStringContainsString('Parent property', $result['contents']);
    }

    public function testHoverOnMultiLevelInheritedMethod(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'grandparent_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('grandparentMethod', $result['contents']);
        self::assertStringContainsString('Grandparent method documentation', $result['contents']);
    }

    public function testHoverOnMultiLevelInheritedProperty(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'grandparent_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$grandparentProperty', $result['contents']);
        self::assertStringContainsString('Grandparent property', $result['contents']);
    }

    public function testHoverOnTraitMethod(): void
    {
        $this->openFixture('src/Traits/HasTimestamps.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'markCreated');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('markCreated', $result['contents']);
    }

    public function testHoverOnTraitProperty(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Traits/HasTimestamps.php', 'trait_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$displayName', $result['contents']);
        self::assertStringContainsString('Display name for the entity', $result['contents']);
    }

    public function testHoverOnInterfaceMethod(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/Person.php', 'interface_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('getName', $result['contents']);
        self::assertStringContainsString("Gets the person's name", $result['contents']);
    }

    public function testHoverOnInheritedMethodAcrossNamespaces(): void
    {
        $cursor = $this->openFixtureAtHoverMarker(
            'Namespacing/CrossNamespaceInheritance.php',
            'cross_namespace_method',
        );

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('baseMethod', $result['contents']);
        self::assertStringContainsString('Method from Base namespace', $result['contents']);
    }

    public function testHoverOnPrivateInheritedMethodReturnsNull(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'private_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertNull($result);
    }

    public function testHoverOnPrivateInheritedPropertyReturnsNull(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'private_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertNull($result);
    }

    public function testHoverOnOverriddenMethodShowsChildVersion(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'overridden_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('overriddenMethod', $result['contents']);
        self::assertStringContainsString('Child implementation', $result['contents']);
        self::assertStringNotContainsString('Parent implementation', $result['contents']);
    }

    public function testHoverOnOverriddenPropertyShowsChildVersion(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'shared_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$sharedProperty', $result['contents']);
        self::assertStringContainsString('Child override', $result['contents']);
        self::assertStringNotContainsString('Shared property from parent', $result['contents']);
    }

    public function testHoverOnStaticProperty(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ParentClass.php', 'staticProperty');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$staticProperty', $result['contents']);
        self::assertStringContainsString('static', $result['contents']);
        self::assertStringContainsString('Static property documentation', $result['contents']);
    }

    public function testHoverOnBuiltinClassMethod(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Hover/BuiltinUsage.php', 'builtin_class_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('getArrayCopy', $result['contents']);
    }

    public function testHoverOnBuiltinClassProperty(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/TypeInference/BuiltinTypes.php', 'builtin_class_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$message', $result['contents']);
    }

    public function testHoverOnMethodWithVariadicParameter(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Repository/ClassInfoPatterns.php', 'variadic_param');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('...$items', $result['contents']);
    }

    public function testHoverOnMethodWithOptionalParameter(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Repository/ClassInfoPatterns.php', 'optional_param');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$count = 0', $result['contents']);
        self::assertStringNotContainsString('$name = ...', $result['contents']);
    }

    public function testHoverOnNullsafeMethodCall(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'setName_nullsafe');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['contents']);
        self::assertStringContainsString('Updates the user', $result['contents']);
    }

    public function testHoverOnNullsafePropertyFetch(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'manager_nullsafe');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$manager', $result['contents']);
        self::assertStringContainsString('User', $result['contents']);
    }

    public function testHoverOnNullsafeTypedVariableMethodCall(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('SignatureHelp.php', 'nullsafeTypedVar');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['contents']);
        self::assertStringContainsString('Updates the user', $result['contents']);
    }

    public function testHoverOnMultilineChainMethod(): void
    {
        $this->openFixture('src/Domain/Team.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'chain_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('withName', $result['contents']);
        self::assertStringContainsString('Sets the name fluently', $result['contents']);
    }

    public function testHoverOnMultilineChainProperty(): void
    {
        $this->openFixture('src/Domain/Team.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'chain_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$team', $result['contents']);
        self::assertStringContainsString('Team', $result['contents']);
    }

    public function testHoverOnMultilineChainCrossType(): void
    {
        $this->openFixture('src/Domain/Team.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'chain_cross_type');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('getLeader', $result['contents']);
        self::assertStringContainsString('Gets the team leader', $result['contents']);
    }

    public function testHoverOnMultilineChainBackToUser(): void
    {
        $this->openFixture('src/Domain/Team.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'chain_back_to_user');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$manager', $result['contents']);
        self::assertStringContainsString('User', $result['contents']);
    }

    public function testHoverOnMultilineChainNullsafe(): void
    {
        $this->openFixture('src/Domain/Team.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'chain_nullsafe');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('withAge', $result['contents']);
        self::assertStringContainsString('Sets the age fluently', $result['contents']);
    }

    public function testHoverOnSelfStaticMethod(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'self_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('staticMethod', $result['contents']);
        self::assertStringContainsString('Static method documentation', $result['contents']);
    }

    public function testHoverOnStaticStaticMethod(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'static_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('staticMethod', $result['contents']);
        self::assertStringContainsString('Static method documentation', $result['contents']);
    }

    public function testHoverOnParentMethod(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'parent_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('parentMethod', $result['contents']);
        self::assertStringContainsString('Parent method documentation', $result['contents']);
    }

    public function testHoverOnSelfStaticProperty(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'self_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$staticProperty', $result['contents']);
        self::assertStringContainsString('Static property documentation', $result['contents']);
    }

    public function testHoverOnStaticStaticProperty(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'static_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$staticProperty', $result['contents']);
        self::assertStringContainsString('Static property documentation', $result['contents']);
    }

    public function testHoverOnParentStaticProperty(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'parent_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$staticProperty', $result['contents']);
        self::assertStringContainsString('Static property documentation', $result['contents']);
    }

    public function testHoverOnSelfMethodOutsideClassReturnsNull(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('EdgeCases/SelfOutsideClass.php', 'self_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertNull($result);
    }

    public function testHoverOnParentMethodWithoutExtendsReturnsNull(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('EdgeCases/ParentWithoutExtends.php', 'parent_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertNull($result);
    }

    public function testHoverOnSelfPropertyOutsideClassReturnsNull(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('EdgeCases/SelfOutsideClass.php', 'self_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertNull($result);
    }

    public function testHoverOnParentPropertyWithoutExtendsReturnsNull(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('EdgeCases/ParentWithoutExtends.php', 'parent_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertNull($result);
    }
}
