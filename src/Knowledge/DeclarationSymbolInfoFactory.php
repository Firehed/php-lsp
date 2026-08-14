<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Document\FileUri;
use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Firehed\PhpLsp\Index\FileDeclarations;
use Firehed\PhpLsp\Repository\ClassInfoFactory;

/**
 * The one place a {@see NameKind} picks a declaration list and a builder, which is
 * what lets {@see SymbolBackend} carry a single lookup and a single registration
 * (Plan 0002 §5.6).
 *
 * Lookup is a filter over {@see allIn()} rather than its own scan: RFC 1 §5.1
 * forbids a derived verb forking from the one it derives from, and a second scan is
 * how the on-disk read path and the open-document write path came to disagree about
 * which declarations count.
 */
final readonly class DeclarationSymbolInfoFactory
{
    public function __construct(
        private ClassInfoFactory $classes,
    ) {
    }

    /**
     * Every symbol the file declares, at any depth. Of duplicates the first wins —
     * the one PHP would define.
     *
     * Global constants are scanned but not built: their info type lands in S3.8b.
     *
     * @return list<DeclaredSymbol>
     */
    public function allIn(FileDeclarations $declarations, string $filePath): array
    {
        $symbols = [];
        $seen = [];

        foreach ($declarations->classLikes as $declaration) {
            $info = $this->classes->fromAstNode($declaration->node, FileUri::fromPath($filePath));
            self::collect($symbols, $seen, $declaration->name, NameKind::ClassLike, $info);
        }
        foreach ($declarations->functions as $declaration) {
            $info = FunctionInfo::fromNode($declaration->node, $filePath);
            self::collect($symbols, $seen, $declaration->name, NameKind::Function_, $info);
        }

        return $symbols;
    }

    public function fromDeclarations(
        FileDeclarations $declarations,
        QualifiedName $name,
        NameKind $kind,
        string $filePath,
    ): ?SymbolInfo {
        $target = $kind->normalize($name);

        foreach ($this->allIn($declarations, $filePath) as $symbol) {
            if ($symbol->kind === $kind && $kind->normalize($symbol->name) === $target) {
                return $symbol->info;
            }
        }

        return null;
    }

    /**
     * @param list<DeclaredSymbol> $symbols
     * @param array<string, true> $seen
     */
    private static function collect(
        array &$symbols,
        array &$seen,
        QualifiedName $name,
        NameKind $kind,
        SymbolInfo $info,
    ): void {
        $key = $kind->name . '|' . $kind->normalize($name);
        if (array_key_exists($key, $seen)) {
            return;
        }

        $seen[$key] = true;
        $symbols[] = new DeclaredSymbol($name, $kind, $info);
    }
}
