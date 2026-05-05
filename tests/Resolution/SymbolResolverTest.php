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
use Firehed\PhpLsp\Resolution\ResolvedMethod;
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
}
