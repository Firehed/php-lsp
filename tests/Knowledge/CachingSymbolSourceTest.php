<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceContents;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\SymbolInfoInterface;
use Firehed\PhpLsp\Knowledge\CachingSymbolSource;
use Firehed\PhpLsp\Knowledge\ComposerAutoloadMapReader;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type Lookup array{
 *   non-empty-string,
 *   callable(SymbolSourceInterface): ?SymbolInfoInterface,
 *   SymbolInfoInterface,
 * }
 */
#[CoversClass(CachingSymbolSource::class)]
final class CachingSymbolSourceTest extends TestCase
{
    use BuildsSymbolInfoTrait;

    private SymbolSourceInterface&MockObject $inner;
    private CachingSymbolSource $source;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(SymbolSourceInterface::class);
        $this->source = new CachingSymbolSource($this->inner, CacheFactory::inMemory());
    }

    /**
     * @return iterable<string, Lookup>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function lookups(): iterable
    {
        $class = ClasslikeName::fromFullyQualified('App\Alpha');
        yield 'class-like' => [
            'lookupClassLike',
            static fn(SymbolSourceInterface $source): ?SymbolInfoInterface => $source->lookupClassLike($class),
            self::classInfo('App\Alpha', file: '/ws/Alpha.php'),
        ];

        $function = FunctionName::fromFullyQualified('App\helper');
        yield 'function' => [
            'lookupFunction',
            static fn(SymbolSourceInterface $source): ?SymbolInfoInterface => $source->lookupFunction($function),
            self::functionInfo($function->qualifiedName, '/ws/helpers.php'),
        ];

        $constant = ConstantName::fromFullyQualified('App\LIMIT');
        yield 'constant' => [
            'lookupConstant',
            static fn(SymbolSourceInterface $source): ?SymbolInfoInterface => $source->lookupConstant($constant),
            self::constantInfo($constant->qualifiedName, '/ws/helpers.php'),
        ];
    }

    /**
     * @param non-empty-string $method
     * @param callable(SymbolSourceInterface): ?SymbolInfoInterface $lookup
     */
    #[DataProvider('lookups')]
    public function testARepeatedLookupAsksTheSourceOnce(
        string $method,
        callable $lookup,
        SymbolInfoInterface $info,
    ): void {
        $this->inner->expects(self::once())->method($method)->willReturn($info);

        $first = $lookup($this->source);
        $second = $lookup($this->source);

        self::assertSame($info, $first, 'the first lookup resolves through the source');
        self::assertSame($first, $second, 'the remembered answer is the same instance');
    }

    public function testAMissIsRemembered(): void
    {
        $name = ClasslikeName::fromFullyQualified('App\Missing');
        $this->inner->expects(self::once())->method('lookupClassLike')->willReturn(null);

        self::assertNull($this->source->lookupClassLike($name));
        self::assertNull($this->source->lookupClassLike($name), 'a miss is remembered like a hit');
    }

    public function testInvalidationDropsEveryMiss(): void
    {
        $name = ClasslikeName::fromFullyQualified('App\Missing');
        // A miss names no file, so any change on disk may have created it.
        $this->inner->expects(self::exactly(2))->method('lookupClassLike')->willReturn(null);

        $this->source->lookupClassLike($name);
        $this->source->invalidate('file:///ws/Anything.php');
        $this->source->lookupClassLike($name);
    }

    public function testInvalidatingAFileDropsOnlyTheSymbolsItDeclares(): void
    {
        $alpha = ClasslikeName::fromFullyQualified('App\Alpha');
        $beta = ClasslikeName::fromFullyQualified('App\Beta');
        // Alpha before and after its file changes, Beta once: another file's
        // symbol stays remembered.
        $this->inner->expects(self::exactly(3))
            ->method('lookupClassLike')
            ->willReturnCallback(static fn(ClasslikeName $name): ClassInfo => self::classInfo(
                $name->qualifiedName->fullyQualifiedName(),
                file: '/ws/' . $name->qualifiedName->shortName . '.php',
            ));

        $this->source->lookupClassLike($alpha);
        $this->source->lookupClassLike($beta);
        $this->source->invalidate('file:///ws/Alpha.php');
        $this->source->lookupClassLike($alpha);
        $this->source->lookupClassLike($beta);
    }

    public function testInvalidationDecodesAPercentEncodedUriToMatchTheDeclaringFile(): void
    {
        $name = ClasslikeName::fromFullyQualified('Spaced');
        // A client URI percent-encodes a space; the declaring path does not.
        $this->inner->expects(self::exactly(2))
            ->method('lookupClassLike')
            ->willReturn(self::classInfo('Spaced', file: '/ws/with space/Spaced.php'));

        $this->source->lookupClassLike($name);
        $this->source->invalidate('file:///ws/with%20space/Spaced.php');
        $this->source->lookupClassLike($name);
    }

    public function testInvalidatingANonFileUriKeepsEveryHit(): void
    {
        $name = ClasslikeName::fromFullyQualified('App\Alpha');
        // An unsaved-buffer URI names no path on disk, so it can match no declaring file.
        $this->inner->expects(self::once())
            ->method('lookupClassLike')
            ->willReturn(self::classInfo('App\Alpha', file: '/ws/Alpha.php'));

        $this->source->lookupClassLike($name);
        $this->source->invalidate('untitled:Untitled-1');
        $this->source->lookupClassLike($name);
    }

    public function testAClassAndAFunctionOfOneNameAreRememberedApart(): void
    {
        // PHP's symbol namespaces are independent, so one name can be both a class
        // and a function; the kind is part of the key.
        $class = self::classInfo('Dual', file: '/ws/Dual.php');
        $function = self::functionInfo(FunctionName::fromFullyQualified('Dual')->qualifiedName, '/ws/Dual.php');
        $this->inner->expects(self::once())->method('lookupClassLike')->willReturn($class);
        $this->inner->expects(self::once())->method('lookupFunction')->willReturn($function);

        $this->source->lookupClassLike(ClasslikeName::fromFullyQualified('Dual'));
        $this->source->lookupFunction(FunctionName::fromFullyQualified('Dual'));

        self::assertSame(
            $function,
            $this->source->lookupFunction(FunctionName::fromFullyQualified('Dual')),
            'a remembered class must not answer a function lookup of the same name',
        );
    }

    public function testASymbolWithNoFileSurvivesInvalidation(): void
    {
        $name = ClasslikeName::fromFullyQualified('ArrayObject');
        // A built-in declares no file, so no file change can affect it.
        $this->inner->expects(self::once())->method('lookupClassLike')->willReturn(self::classInfo('ArrayObject'));

        $this->source->lookupClassLike($name);
        $this->source->invalidate('file:///ws/Alpha.php');
        $this->source->lookupClassLike($name);
    }

    public function testARepeatedListingAsksTheSourceOnce(): void
    {
        $contents = new NamespaceContents(['App\Sub'], []);
        // Namespaces are case-insensitive, so `App` and `app` are one listing.
        $this->inner->expects(self::once())->method('childrenOf')->willReturn($contents);

        $first = $this->source->childrenOf(new NamespaceName('App'));
        $second = $this->source->childrenOf(new NamespaceName('app'));

        self::assertSame($contents, $first, 'the first listing resolves through the source');
        self::assertSame($first, $second, 'the remembered listing is the same instance');
    }

    public function testInvalidationDropsEveryListing(): void
    {
        // A listing is keyed by namespace, not by file, so any change on disk re-reads it.
        $this->inner->expects(self::exactly(2))->method('childrenOf')->willReturn(new NamespaceContents());

        $this->source->childrenOf(new NamespaceName('App'));
        $this->source->invalidate('file:///elsewhere/Unrelated.php');
        $this->source->childrenOf(new NamespaceName('App'));
    }

    public function testSearchIsPassedThrough(): void
    {
        // A prefix changes on every keystroke, so search is never remembered.
        $this->inner->expects(self::exactly(2))->method('search')->with('Al', NameKind::ClassLike)->willReturn([]);

        $this->source->search('Al', NameKind::ClassLike);
        $this->source->search('Al', NameKind::ClassLike);
    }

    public function testAMapRegenerationDropsEveryCachedEntry(): void
    {
        // A regenerated Composer map means every remembered lookup, hit or miss,
        // and every cached listing is derived from a map the inner no longer
        // uses. The decorator observes the reader's identity change on
        // invalidate and drops every cached entry — the wholesale drop the
        // per-path accounting can't express through a single URI.
        $workspace = self::makeWorkspace();
        self::writePsr4Map($workspace, ['App\\' => ['/tmp/app']]);
        $mapReader = new ComposerAutoloadMapReader($workspace);

        $alpha = ClasslikeName::fromFullyQualified('App\Alpha');
        $missing = ClasslikeName::fromFullyQualified('App\Missing');
        $this->inner->expects(self::exactly(4))
            ->method('lookupClassLike')
            ->willReturnMap([
                [$alpha, self::classInfo('App\Alpha', file: '/ws/Alpha.php')],
                [$missing, null],
            ]);
        $this->inner->expects(self::exactly(2))
            ->method('childrenOf')
            ->willReturn(new NamespaceContents());

        $source = new CachingSymbolSource($this->inner, CacheFactory::inMemory(), $mapReader);

        $source->lookupClassLike($alpha);
        $source->lookupClassLike($missing);
        $source->childrenOf(new NamespaceName('App'));

        self::writePsr4Map($workspace, ['App\\' => ['/tmp/app'], 'Lib\\' => ['/tmp/lib']]);
        $mapReader->invalidate(FileUri::fromPath($workspace . '/vendor/composer/autoload_psr4.php'));
        $source->invalidate(FileUri::fromPath($workspace . '/vendor/composer/autoload_psr4.php'));

        // Every cached read must consult the inner again.
        $source->lookupClassLike($alpha);
        $source->lookupClassLike($missing);
        $source->childrenOf(new NamespaceName('App'));

        self::cleanWorkspace($workspace);
    }

    private static function makeWorkspace(): string
    {
        $workspace = tempnam(sys_get_temp_dir(), 'php-lsp-caching-');
        self::assertNotFalse($workspace, 'a temp workspace path must be obtainable');
        unlink($workspace);
        self::assertTrue(mkdir($workspace . '/vendor/composer', 0777, true), 'vendor/composer must be creatable');

        return $workspace;
    }

    /**
     * @param array<string, list<string>> $prefixes
     */
    private static function writePsr4Map(string $workspace, array $prefixes): void
    {
        $path = $workspace . '/vendor/composer/autoload_psr4.php';
        self::assertNotFalse(
            file_put_contents($path, "<?php\nreturn " . var_export($prefixes, true) . ";\n"),
            'the generated PSR-4 map must be writable',
        );
    }

    private static function cleanWorkspace(string $workspace): void
    {
        $entries = glob($workspace . '/vendor/composer/*');
        foreach ($entries === false ? [] : $entries as $file) {
            unlink($file);
        }
        @rmdir($workspace . '/vendor/composer');
        @rmdir($workspace . '/vendor');
        @rmdir($workspace);
    }
}
