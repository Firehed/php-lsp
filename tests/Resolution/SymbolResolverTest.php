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
use Firehed\PhpLsp\Resolution\ResolvedMethod;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\TypeInference\BasicTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolResolver::class)]
final class SymbolResolverTest extends TestCase
{
    use LoadsFixturesTrait;

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

    private function openDocument(string $uri, string $code): TextDocument
    {
        $this->syncHandler->handle(NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => [
                'textDocument' => [
                    'uri' => $uri,
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $code,
                ],
            ],
        ]));

        $document = $this->documents->get($uri);
        assert($document !== null);
        return $document;
    }

    public function testResolveAtPositionReturnsNullForEmptyDocument(): void
    {
        $document = $this->openDocument('file:///test.php', '<?php ');

        $result = $this->resolver->resolveAtPosition($document, 0, 5);

        self::assertNull($result);
    }

    public function testResolvesInstanceMethodCall(): void
    {
        $code = <<<'PHP'
<?php
class Foo {
    public function bar(): void {}
    public function test(): void {
        $this->bar();
    }
}
PHP;
        $document = $this->openDocument('file:///test.php', $code);
        // Position on "bar" in "$this->bar()"
        // Line 4 (0-indexed), character 16 (on 'bar')
        $result = $this->resolver->resolveAtPosition($document, 4, 16);

        self::assertInstanceOf(ResolvedMethod::class, $result);
        self::assertSame('public function bar(): void', $result->format());
    }

    public function testResolvesNullsafeMethodCall(): void
    {
        $code = <<<'PHP'
<?php
class Foo {
    public function bar(): void {}
}
class Container {
    public ?Foo $foo = null;
    public function test(): void {
        $this->foo?->bar();
    }
}
PHP;
        $document = $this->openDocument('file:///test.php', $code);
        // Position on "bar" in "$this->foo?->bar()"
        // Line 7 (0-indexed), character 22 (on 'bar')
        $result = $this->resolver->resolveAtPosition($document, 7, 22);

        self::assertInstanceOf(ResolvedMethod::class, $result);
        self::assertSame('public function bar(): void', $result->format());
    }
}
