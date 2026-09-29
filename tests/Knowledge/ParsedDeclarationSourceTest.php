<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Knowledge\DeclarationScanner;
use Firehed\PhpLsp\Knowledge\DeclarationSymbolInfoFactory;
use Firehed\PhpLsp\Knowledge\ParsedDeclarationSource;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsedDeclarationSource::class)]
final class ParsedDeclarationSourceTest extends TestCase
{
    use LoadsFixturesTrait;

    private const string FIXTURE = 'src/Domain/User.php';

    /**
     * @return iterable<string, array{callable(string): string}>
     */
    public static function spellings(): iterable
    {
        yield 'a path, as the disk reader names a document' => [static fn(string $path): string => $path];
        yield 'a URI, as a client names a document' => [FileUri::fromPath(...)];
    }

    /**
     * @param callable(string): string $spell
     */
    #[DataProvider('spellings')]
    public function testItReportsWhatTheDocumentDeclares(callable $spell): void
    {
        $path = $this->fixturePath(self::FIXTURE);
        $source = new ParsedDeclarationSource(
            ProductionSyntaxSource::create()->source,
            new DeclarationScanner(),
            new DeclarationSymbolInfoFactory(),
        );

        $declared = $source->declarationsIn(
            new TextDocument($spell($path), 'php', 1, $this->loadFixture(self::FIXTURE)),
        );

        self::assertSame(
            [['Fixtures\Domain\User', NameKind::ClassLike]],
            array_map(
                static fn(DeclaredSymbol $symbol): array => [$symbol->name->fullyQualifiedName(), $symbol->kind],
                $declared,
            ),
            'the fixture declares one class and nothing else',
        );
        $info = $declared[0]->info;
        self::assertInstanceOf(ClassInfo::class, $info, 'a class-like is described by a ClassInfo');
        self::assertSame($path, $info->file, 'the declaring file is a path however the document was named');
    }
}
