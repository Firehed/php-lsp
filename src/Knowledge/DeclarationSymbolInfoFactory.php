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
 * Builds the metadata for a name a parsed file declares, given the kind it is being
 * asked for.
 *
 * This is the one place a {@see NameKind} decides which declaration list to search
 * and which factory builds the result — the whole of what the kind changes on this
 * route (Plan 0002 §5.6). Because it is confined here, {@see SymbolBackend} carries a
 * single kind-parameterized lookup, and a new kind is a case in this match rather
 * than a method on every backend.
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
            // The declarations are scanned; what is missing is the global-constant
            // info type, which S3.8b lands with the Domain\ConstantName naming
            // clash it forces (build-manifest S3.8b).
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
