<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Firehed\PhpLsp\Repository\ClassInfoFactory;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;

/**
 * The reflection counterpart of {@see DeclarationSymbolInfoFactory}, describing the
 * *server's* runtime rather than the project's target — the §4.7 gap deferred to
 * Step 5.
 */
final readonly class ReflectionSymbolInfoFactory
{
    public function __construct(
        private ClassInfoFactory $classes,
    ) {
    }

    public function fromReflection(QualifiedName $name, NameKind $kind): ?SymbolInfo
    {
        return match ($kind) {
            NameKind::ClassLike => $this->classInfo($name),
            NameKind::Function_ => $this->functionInfo($name),
            // Reflectable, but the info type lands in S3.8b.
            NameKind::Constant => null,
        };
    }

    private function classInfo(QualifiedName $name): ?SymbolInfo
    {
        try {
            $reflection = new ReflectionClass($name->fullyQualifiedName());
        } catch (ReflectionException) {
            return null;
        }

        return $this->classes->fromReflection($reflection);
    }

    private function functionInfo(QualifiedName $name): ?SymbolInfo
    {
        try {
            $reflection = new ReflectionFunction($name->fullyQualifiedName());
        } catch (ReflectionException) {
            return null;
        }

        // Reflection also sees the server's own dependencies; enumeration filters
        // those out, so lookup must too (RFC 1 §4.2).
        return $reflection->isInternal() ? FunctionInfo::fromReflection($reflection) : null;
    }
}
