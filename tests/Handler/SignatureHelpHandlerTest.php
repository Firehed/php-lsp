<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\SignatureHelpHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use Firehed\PhpLsp\Utility\MemberAccessResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SignatureHelpHandler::class)]
class SignatureHelpHandlerTest extends TestCase
{
    use OpensDocumentsTrait;

    private DocumentManager $documents;
    private ParserService $parser;
    private DefaultClassRepository $classRepository;
    private DefaultClassInfoFactory $classInfoFactory;
    private MemberResolver $memberResolver;
    private SignatureHelpHandler $handler;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager();
        $this->parser = new ParserService();
        $this->classInfoFactory = new DefaultClassInfoFactory();
        $locator = new ComposerClassLocator(__DIR__ . '/../Fixtures');
        $this->classRepository = new DefaultClassRepository(
            $this->classInfoFactory,
            $locator,
            $this->parser,
        );
        $this->memberResolver = new MemberResolver($this->classRepository);
        $typeResolver = new BasicTypeResolver($this->memberResolver);
        $this->handler = new SignatureHelpHandler(
            $this->documents,
            $this->parser,
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
        self::assertTrue($this->handler->supports('textDocument/signatureHelp'));
        self::assertFalse($this->handler->supports('textDocument/hover'));
    }

    public function testSignatureHelpOnUserDefinedFunction(): void
    {
        $cursor = $this->openFixtureAtCursor('SignatureHelp.php', 'first_param');
        $result = $this->handler->handle($this->signatureHelpRequestAt($cursor));

        self::assertIsArray($result);
        self::assertArrayHasKey('signatures', $result);
        self::assertCount(1, $result['signatures']);
        self::assertStringContainsString('signatureHelpAdd', $result['signatures'][0]['label']);
        self::assertStringContainsString('int $a', $result['signatures'][0]['label']);
        self::assertEquals(0, $result['activeParameter']);
        $doc = $result['signatures'][0]['documentation'] ?? '';
        self::assertStringContainsString('Adds two numbers', $doc);
    }

    public function testSignatureHelpSecondParameter(): void
    {
        $cursor = $this->openFixtureAtCursor('SignatureHelp.php', 'second_param');
        $result = $this->handler->handle($this->signatureHelpRequestAt($cursor));

        self::assertIsArray($result);
        self::assertEquals(1, $result['activeParameter']);
    }

    public function testSignatureHelpOnBuiltinFunction(): void
    {
        $cursor = $this->openFixtureAtCursor('SignatureHelp.php', 'builtin');
        $result = $this->handler->handle($this->signatureHelpRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('array_map', $result['signatures'][0]['label']);
    }

    public function testSignatureHelpOnMethod(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Domain/User.php', 'sig_this_call');
        $result = $this->handler->handle($this->signatureHelpRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['signatures'][0]['label']);
        $doc = $result['signatures'][0]['documentation'] ?? '';
        self::assertStringContainsString("Updates the user's display name", $doc);
    }

    public function testSignatureHelpOnStaticMethod(): void
    {
        $cursor = $this->openFixtureAtCursor('SignatureHelp.php', 'static_call');
        $result = $this->handler->handle($this->signatureHelpRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('fromScore', $result['signatures'][0]['label']);
        self::assertStringContainsString('int $score', $result['signatures'][0]['label']);
    }

    public function testSignatureHelpOnSelfStaticMethod(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Domain/User.php', 'sig_self_call');
        $result = $this->handler->handle($this->signatureHelpRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('create', $result['signatures'][0]['label']);
        self::assertStringContainsString('string $id', $result['signatures'][0]['label']);
    }

    public function testSignatureHelpOnConstructor(): void
    {
        $cursor = $this->openFixtureAtCursor('SignatureHelp.php', 'constructor');
        $result = $this->handler->handle($this->signatureHelpRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('__construct', $result['signatures'][0]['label']);
        self::assertStringContainsString('string $id', $result['signatures'][0]['label']);
    }

    public function testSignatureHelpReturnsNullOutsideCall(): void
    {
        $cursor = $this->openFixtureAtCursor('SignatureHelp.php', 'outside_call');
        $result = $this->handler->handle($this->signatureHelpRequestAt($cursor));

        self::assertNull($result);
    }

    public function testSignatureHelpOnTypedVariableMethodCall(): void
    {
        $cursor = $this->openFixtureAtCursor('SignatureHelp.php', 'typed_param');
        $result = $this->handler->handle($this->signatureHelpRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['signatures'][0]['label']);
        $doc = $result['signatures'][0]['documentation'] ?? '';
        self::assertStringContainsString("Updates the user's display name", $doc);
    }

    public function testSignatureHelpOnAssignedVariableMethodCall(): void
    {
        $cursor = $this->openFixtureAtCursor('SignatureHelp.php', 'assigned_var');
        $result = $this->handler->handle($this->signatureHelpRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['signatures'][0]['label']);
        $doc = $result['signatures'][0]['documentation'] ?? '';
        self::assertStringContainsString("Updates the user's display name", $doc);
    }

    public function testSignatureHelpOnNullsafeMethodCall(): void
    {
        $cursor = $this->openFixtureAtCursor('src/Domain/User.php', 'sig_nullsafe_property');
        $result = $this->handler->handle($this->signatureHelpRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['signatures'][0]['label']);
        $doc = $result['signatures'][0]['documentation'] ?? '';
        self::assertStringContainsString("Updates the user's display name", $doc);
    }

    public function testSignatureHelpOnNullsafeTypedVariableMethodCall(): void
    {
        $cursor = $this->openFixtureAtCursor('SignatureHelp.php', 'nullsafe_param');
        $result = $this->handler->handle($this->signatureHelpRequestAt($cursor));

        self::assertIsArray($result);
        self::assertStringContainsString('setName', $result['signatures'][0]['label']);
        $doc = $result['signatures'][0]['documentation'] ?? '';
        self::assertStringContainsString("Updates the user's display name", $doc);
    }
}
