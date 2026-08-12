<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Document\FileUri;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Firehed\PhpLsp\Index\Declaration;
use Firehed\PhpLsp\Index\FileDeclarations;
use Firehed\PhpLsp\Repository\ClassInfoFactory;
use PhpParser\Node;
use PhpParser\Node\Stmt;

/**
 * The one place a {@see NameKind} picks a declaration list and a builder, which is
 * what lets {@see SymbolBackend} carry a single lookup (Plan 0002 §5.6).
 */
final readonly class DeclarationSymbolInfoFactory
{
    public function __construct(
        private ClassInfoFactory $classes,
    ) {
    }

    public function fromDeclarations(
        FileDeclarations $declarations,
        QualifiedName $name,
        NameKind $kind,
        string $filePath,
    ): ?SymbolInfo {
        $target = $kind->normalize($name);

        return match ($kind) {
            NameKind::ClassLike => $this->firstMatching(
                $declarations->classLikes,
                $target,
                $kind,
                fn(Stmt\ClassLike $node): SymbolInfo => $this->classes->fromAstNode(
                    $node,
                    FileUri::fromPath($filePath),
                ),
            ),
            NameKind::Function_ => $this->firstMatching(
                $declarations->functions,
                $target,
                $kind,
                static fn(Stmt\Function_ $node): SymbolInfo => FunctionInfo::fromNode($node, $filePath),
            ),
            // Scanned, but the global-constant info type lands in S3.8b.
            NameKind::Constant => null,
        };
    }

    /**
     * @template TNode of Node
     * @param list<Declaration<TNode>> $declarations
     * @param callable(TNode): SymbolInfo $build
     */
    private function firstMatching(array $declarations, string $target, NameKind $kind, callable $build): ?SymbolInfo
    {
        foreach ($declarations as $declaration) {
            if ($kind->normalize($declaration->name) === $target) {
                return $build($declaration->node);
            }
        }

        return null;
    }
}
