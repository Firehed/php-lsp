<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
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

    protected function setUp(): void
    {
        $this->parser = new ParserService();
        $classInfoFactory = new DefaultClassInfoFactory();
        $locator = self::createStub(ClassLocator::class);
        $classRepository = new DefaultClassRepository(
            $classInfoFactory,
            $locator,
            $this->parser,
        );
        $memberResolver = new MemberResolver($classRepository);
        $typeResolver = new BasicTypeResolver($memberResolver);

        $this->resolver = new SymbolResolver(
            parser: $this->parser,
            classRepository: $classRepository,
            memberResolver: $memberResolver,
            typeResolver: $typeResolver,
        );
    }

    public function testResolveAtPositionReturnsNullForEmptyDocument(): void
    {
        $document = new TextDocument('file:///test.php', 'php', 1, '<?php ');

        $result = $this->resolver->resolveAtPosition($document, 0, 5);

        self::assertNull($result);
    }
}
