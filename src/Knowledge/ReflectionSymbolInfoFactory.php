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
        $fqn = $name->fullyQualifiedName();

        // Also what narrows the name to a `class-string`, which is why this kind
        // cannot use the sibling's try/catch. All three, since `class_exists`
        // answers for classes and enums only; each autoloads exactly as
        // constructing the reflection would.
        if (!class_exists($fqn) && !interface_exists($fqn) && !trait_exists($fqn)) {
            return null;
        }

        return $this->classes->fromReflection(new ReflectionClass($fqn));
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
