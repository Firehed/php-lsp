<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Document\FileUri;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Firehed\PhpLsp\Index\FileDeclarations;
use Firehed\PhpLsp\Repository\ClassInfoFactory;

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
            NameKind::ClassLike => $this->classLike($declarations, $target, $filePath),
            NameKind::Function_ => self::standaloneFunction($declarations, $target, $filePath),
            // Scanned, but the global-constant info type lands in S3.8b.
            NameKind::Constant => null,
        };
    }

    private function classLike(FileDeclarations $declarations, string $target, string $filePath): ?ClassInfo
    {
        foreach ($declarations->classLikes as $declaration) {
            if (NameKind::ClassLike->normalize($declaration->name) === $target) {
                return $this->classes->fromAstNode($declaration->node, FileUri::fromPath($filePath));
            }
        }

        return null;
    }

    private static function standaloneFunction(
        FileDeclarations $declarations,
        string $target,
        string $filePath,
    ): ?FunctionInfo {
        foreach ($declarations->functions as $declaration) {
            if (NameKind::Function_->normalize($declaration->name) === $target) {
                return FunctionInfo::fromNode($declaration->node, $filePath);
            }
        }

        return null;
    }
}
