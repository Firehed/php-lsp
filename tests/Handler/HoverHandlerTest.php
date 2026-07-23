<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\HoverHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\MarkupKind;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\DefaultFunctionRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
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
    private SymbolResolver $symbolResolver;
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
        $memberResolver = new MemberResolver($this->classRepository);
        $typeResolver = new BasicTypeResolver($memberResolver, new DefaultFunctionRepository());
        $this->symbolResolver = new SymbolResolver(
            $this->parser,
            $this->classRepository,
            $memberResolver,
            $typeResolver,
            new DefaultFunctionRepository(),
        );
        // The default markup kind is plaintext (the pre-initialize default a
        // minimal client is served); the fenced-markdown path is exercised
        // explicitly below.
        $this->handler = $this->handlerFor(MarkupKind::PlainText);
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

    public function testHoverContentsAdvertiseTheNegotiatedMarkupKind(): void
    {
        $handler = $this->handlerFor(MarkupKind::Markdown);
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'setName');

        $result = $handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertSame(
            'markdown',
            $result['contents']['kind'],
            'the MarkupContent kind must reflect the client-negotiated hover format',
        );
    }

    public function testMarkdownHoverFencesTheSignatureAsPhp(): void
    {
        $handler = $this->handlerFor(MarkupKind::Markdown);
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'setName');

        $result = $handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString(
            '```php',
            $result['contents']['value'],
            'a markdown client renders the signature inside a fenced PHP block',
        );
    }

    public function testPlainTextHoverOmitsTheMarkdownFences(): void
    {
        $handler = $this->handlerFor(MarkupKind::PlainText);
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'setName');

        $result = $handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertSame('plaintext', $result['contents']['kind'], 'the kind must degrade with the client');
        self::assertStringNotContainsString(
            '```',
            $result['contents']['value'],
            'a plaintext client would show markdown fences literally, so they must not be emitted',
        );
        self::assertStringContainsString(
            'setName',
            $result['contents']['value'],
            'the signature itself is still present, just unfenced',
        );
    }

    public function testHoverOnClassWithDocblock(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('SignatureHelp.php', 'class_instantiation');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('User', $result['contents']['value']);
        self::assertStringContainsString('Represents a system user', $result['contents']['value']);
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
        self::assertStringContainsString('signatureHelpAdd', $result['contents']['value']);
        self::assertStringContainsString('int', $result['contents']['value']);
        self::assertStringContainsString('Adds two numbers together', $result['contents']['value']);
    }

    public function testHoverOnMethod(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'setName');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['contents']['value']);
        self::assertStringContainsString('Updates the user', $result['contents']['value']);
    }

    public function testHoverOnMethodCallInGlobalScope(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('TopLevel/global_scope_hover.php', 'global_method_call');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('getName', $result['contents']['value']);
    }

    public function testHoverOnProperty(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'manager');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$manager', $result['contents']['value']);
        self::assertStringContainsString('User', $result['contents']['value']);
    }

    public function testHoverOnBuiltinFunction(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Hover/BuiltinUsage.php', 'builtin_function');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('sort', $result['contents']['value']);
    }

    public function testHoverOnStaticMethod(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'create');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('create', $result['contents']['value']);
    }

    public function testHoverOnTypedVariableMethodCall(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('SignatureHelp.php', 'typedVarMethod');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['contents']['value']);
        self::assertStringContainsString('Updates the user', $result['contents']['value']);
    }

    public function testHoverOnAssignedVariableMethodCall(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('SignatureHelp.php', 'assignedVarMethod');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['contents']['value']);
        self::assertStringContainsString('Updates the user', $result['contents']['value']);
    }

    public function testHoverOnInheritedMethod(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'inherited_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('parentMethod', $result['contents']['value']);
        self::assertStringContainsString('Parent method documentation', $result['contents']['value']);
    }

    public function testHoverOnInheritedProperty(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'inherited_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$parentProperty', $result['contents']['value']);
        self::assertStringContainsString('Parent property', $result['contents']['value']);
    }

    public function testHoverOnMultiLevelInheritedMethod(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'grandparent_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('grandparentMethod', $result['contents']['value']);
        self::assertStringContainsString('Grandparent method documentation', $result['contents']['value']);
    }

    public function testHoverOnMultiLevelInheritedProperty(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'grandparent_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$grandparentProperty', $result['contents']['value']);
        self::assertStringContainsString('Grandparent property', $result['contents']['value']);
    }

    public function testHoverOnTraitMethod(): void
    {
        $this->openFixture('src/Traits/HasTimestamps.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'markCreated');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('markCreated', $result['contents']['value']);
    }

    public function testHoverOnTraitProperty(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Traits/HasTimestamps.php', 'trait_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$displayName', $result['contents']['value']);
        self::assertStringContainsString('Display name for the entity', $result['contents']['value']);
    }

    public function testHoverOnInterfaceMethod(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/Person.php', 'interface_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('getName', $result['contents']['value']);
        self::assertStringContainsString("Gets the person's name", $result['contents']['value']);
    }

    public function testHoverOnInheritedMethodAcrossNamespaces(): void
    {
        $cursor = $this->openFixtureAtHoverMarker(
            'Namespacing/CrossNamespaceInheritance.php',
            'cross_namespace_method',
        );

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('baseMethod', $result['contents']['value']);
        self::assertStringContainsString('Method from Base namespace', $result['contents']['value']);
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
        self::assertStringContainsString('overriddenMethod', $result['contents']['value']);
        self::assertStringContainsString('Child implementation', $result['contents']['value']);
        self::assertStringNotContainsString('Parent implementation', $result['contents']['value']);
    }

    public function testHoverOnOverriddenPropertyShowsChildVersion(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'shared_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$sharedProperty', $result['contents']['value']);
        self::assertStringContainsString('Child override', $result['contents']['value']);
        self::assertStringNotContainsString('Shared property from parent', $result['contents']['value']);
    }

    public function testHoverOnStaticProperty(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ParentClass.php', 'staticProperty');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$staticProperty', $result['contents']['value']);
        self::assertStringContainsString('static', $result['contents']['value']);
        self::assertStringContainsString('Static property documentation', $result['contents']['value']);
    }

    public function testHoverOnBuiltinClassMethod(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Hover/BuiltinUsage.php', 'builtin_class_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('getArrayCopy', $result['contents']['value']);
    }

    public function testHoverOnBuiltinClassProperty(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/TypeInference/BuiltinTypes.php', 'builtin_class_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$message', $result['contents']['value']);
    }

    public function testHoverOnMethodWithVariadicParameter(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Repository/ClassInfoPatterns.php', 'variadic_param');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('...$items', $result['contents']['value']);
    }

    public function testHoverOnMethodWithOptionalParameter(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Repository/ClassInfoPatterns.php', 'optional_param');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$count = 0', $result['contents']['value']);
        self::assertStringNotContainsString('$name = ...', $result['contents']['value']);
    }

    public function testHoverOnNullsafeMethodCall(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'setName_nullsafe');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['contents']['value']);
        self::assertStringContainsString('Updates the user', $result['contents']['value']);
    }

    public function testHoverOnNullsafePropertyFetch(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'manager_nullsafe');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$manager', $result['contents']['value']);
        self::assertStringContainsString('User', $result['contents']['value']);
    }

    public function testHoverOnNullsafeTypedVariableMethodCall(): void
    {
        $this->openFixture('src/Domain/User.php');
        $cursor = $this->openFixtureAtHoverMarker('SignatureHelp.php', 'nullsafeTypedVar');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['contents']['value']);
        self::assertStringContainsString('Updates the user', $result['contents']['value']);
    }

    public function testHoverOnMultilineChainMethod(): void
    {
        $this->openFixture('src/Domain/Team.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'chain_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('withName', $result['contents']['value']);
        self::assertStringContainsString('Sets the name fluently', $result['contents']['value']);
    }

    public function testHoverOnMultilineChainProperty(): void
    {
        $this->openFixture('src/Domain/Team.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'chain_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$team', $result['contents']['value']);
        self::assertStringContainsString('Team', $result['contents']['value']);
    }

    public function testHoverOnMultilineChainCrossType(): void
    {
        $this->openFixture('src/Domain/Team.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'chain_cross_type');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('getLeader', $result['contents']['value']);
        self::assertStringContainsString('Gets the team leader', $result['contents']['value']);
    }

    public function testHoverOnMultilineChainBackToUser(): void
    {
        $this->openFixture('src/Domain/Team.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'chain_back_to_user');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$manager', $result['contents']['value']);
        self::assertStringContainsString('User', $result['contents']['value']);
    }

    public function testHoverOnMultilineChainNullsafe(): void
    {
        $this->openFixture('src/Domain/Team.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'chain_nullsafe');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('withAge', $result['contents']['value']);
        self::assertStringContainsString('Sets the age fluently', $result['contents']['value']);
    }

    public function testHoverOnSelfStaticMethod(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'self_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('staticMethod', $result['contents']['value']);
        self::assertStringContainsString('Static method documentation', $result['contents']['value']);
    }

    public function testHoverOnStaticStaticMethod(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'static_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('staticMethod', $result['contents']['value']);
        self::assertStringContainsString('Static method documentation', $result['contents']['value']);
    }

    public function testHoverOnParentMethod(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'parent_method');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('parentMethod', $result['contents']['value']);
        self::assertStringContainsString('Parent method documentation', $result['contents']['value']);
    }

    public function testHoverOnSelfStaticProperty(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'self_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$staticProperty', $result['contents']['value']);
        self::assertStringContainsString('Static property documentation', $result['contents']['value']);
    }

    public function testHoverOnStaticStaticProperty(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'static_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$staticProperty', $result['contents']['value']);
        self::assertStringContainsString('Static property documentation', $result['contents']['value']);
    }

    public function testHoverOnParentStaticProperty(): void
    {
        $this->openFixture('src/Inheritance/Grandparent.php');
        $this->openFixture('src/Inheritance/ParentClass.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ChildClass.php', 'parent_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$staticProperty', $result['contents']['value']);
        self::assertStringContainsString('Static property documentation', $result['contents']['value']);
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

    /**
     * @see https://github.com/Firehed/php-lsp/issues/6
     */
    public function testHoverOnClassConstant(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Inheritance/ParentClass.php', 'class_constant');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('PARENT_CONST', $result['contents']['value']);
    }

    /**
     * @see https://github.com/Firehed/php-lsp/issues/4
     */
    public function testHoverOnTypedVariable(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'variable_typed');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$typed', $result['contents']['value']);
        self::assertStringContainsString('User', $result['contents']['value']);
    }

    /**
     * @see https://github.com/Firehed/php-lsp/issues/8
     */
    public function testHoverOnPromotedProperty(): void
    {
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'promoted_property');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$id', $result['contents']['value']);
        self::assertStringContainsString('string', $result['contents']['value']);
        self::assertStringContainsString('readonly', $result['contents']['value']);
    }

    /**
     * @see https://github.com/Firehed/php-lsp/issues/185
     */
    public function testHoverOnTraitPropertyFromConsumingClass(): void
    {
        $this->openFixture('src/Traits/HasTimestamps.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Domain/User.php', 'trait_property_consumer');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('$createdAt', $result['contents']['value']);
        self::assertStringContainsString('DateTimeImmutable', $result['contents']['value']);
    }

    // =========================================================================
    // Expressions inside control structures (#267/#243)
    // =========================================================================

    public function testHoverOnPropertyInIfCondition(): void
    {
        $cursor = $this->openFixtureAtHoverMarker(
            'src/IncompleteCode/CompleteInControl.php',
            'prop_in_if',
        );

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result, 'Hover should work on property in if condition');
        self::assertStringContainsString('$name', $result['contents']['value']);
        self::assertStringContainsString('string', $result['contents']['value']);
    }

    public function testHoverOnMethodInWhileCondition(): void
    {
        $cursor = $this->openFixtureAtHoverMarker(
            'src/IncompleteCode/CompleteInControl.php',
            'method_in_while',
        );

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result, 'Hover should work on method in while condition');
        self::assertStringContainsString('getName', $result['contents']['value']);
    }

    public function testHoverOnAttributeClass(): void
    {
        $this->openFixture('src/Attributes/Route.php');
        $cursor = $this->openFixtureAtHoverMarker('src/Services/ApiController.php', 'attr_class');

        $result = $this->handler->handle($this->hoverRequestAt($cursor));

        self::assertIsArray($result, 'Hover should resolve the class in a #[Attribute] usage');
        self::assertStringContainsString('Route', $result['contents']['value']);
        self::assertStringContainsString('Defines a route', $result['contents']['value']);
    }

    private function handlerFor(MarkupKind $hoverMarkupKind): HoverHandler
    {
        $capabilities = self::createStub(SessionCapabilitiesProvider::class);
        $capabilities->method('getSessionCapabilities')
            ->willReturn(new SessionCapabilities(hoverMarkupKind: $hoverMarkupKind));

        return new HoverHandler($this->documents, $this->symbolResolver, $capabilities);
    }
}
