<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Resolution\DefaultTextSymbolExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers the case-by-case shape of the text producer that the sink's tier-3 fallback
 * feeds: multiple kinds of class-like, deduplication within one file, and a constant
 * whose visibility modifier the source omits (PHP defaults it to public).
 */
#[CoversClass(DefaultTextSymbolExtractor::class)]
final class DefaultTextSymbolExtractorTest extends TestCase
{
    private DefaultTextSymbolExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new DefaultTextSymbolExtractor();
    }

    public function testExtractsInterfaceTraitAndEnumInOneFile(): void
    {
        $content = <<<'PHP'
        <?php
        namespace V;
        interface Iface {}
        trait Mixin {}
        enum Kind {}
        PHP;

        $symbols = $this->extractor->extract($content, '/virtual/M.php');
        $byName = [];
        foreach ($symbols as $symbol) {
            $info = $symbol->info;
            self::assertInstanceOf(ClassInfo::class, $info);
            $byName[$symbol->name->fullyQualifiedName()] = $info;
        }

        self::assertSame(ClassKind::Interface_, $byName['V\Iface']->kind);
        self::assertSame(ClassKind::Trait_, $byName['V\Mixin']->kind);
        self::assertSame(ClassKind::Enum_, $byName['V\Kind']->kind);
    }

    public function testDeduplicatesRepeatedDeclarationsWithinAFile(): void
    {
        // A guarded polyfill spells the same class twice; the first wins, matching
        // how the AST-based scanner and PHP itself resolve duplicate declarations.
        $content = <<<'PHP'
        <?php
        namespace V;
        class Widget {}
        if (false) {
            class Widget {}
        }
        PHP;

        $symbols = $this->extractor->extract($content, '/virtual/M.php');
        $matching = array_filter(
            $symbols,
            static fn($symbol): bool => $symbol->kind === NameKind::ClassLike
                && $symbol->name->fullyQualifiedName() === 'V\Widget',
        );
        self::assertCount(1, $matching, 'a repeated class-like declaration must dedupe to one');
    }

    public function testAConstantWithNoExplicitVisibilityDefaultsToPublic(): void
    {
        $content = <<<'PHP'
        <?php
        namespace V;
        class Widget {
            const IMPLICIT = 1;
        PHP;

        $symbols = $this->extractor->extract($content, '/virtual/W.php');
        self::assertCount(1, $symbols);
        $info = $symbols[0]->info;
        self::assertInstanceOf(ClassInfo::class, $info);
        self::assertSame(
            Visibility::Public,
            $info->constants['IMPLICIT']->visibility,
            'PHP defaults an unmodified `const` to public; the text producer must too',
        );
    }
}
