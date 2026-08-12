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
 * Builds the metadata for a symbol the server runtime has loaded, given the kind it
 * is being asked for — the reflection counterpart of
 * {@see DeclarationSymbolInfoFactory}, and the same confinement: the kind selects
 * which reflection describes the name and nothing else (Plan 0002 §5.6).
 *
 * Reflection describes the *server's* runtime rather than the project's target,
 * which is the known §4.7 gap deferred to Step 5.
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
            // Reflection can read a global constant, but there is no info type to
            // build; S3.8b lands it (build-manifest S3.8b).
            NameKind::Constant => null,
        };
    }

    private function classInfo(QualifiedName $name): ?SymbolInfo
    {
        $fqn = $name->fullyQualifiedName();

        // All three, because only `class` and `enum` answer to `class_exists`; an
        // interface or trait is a class-like this backend must still describe. Each
        // autoloads exactly as constructing the reflection would, so this is the
        // same absence test, stated in a form that also carries the name's type.
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

        // Reflection also sees the functions the server's own dependencies declare,
        // which are not the project's. Enumeration is filtered to internal
        // (BuiltinFunctionParityTest), so lookup must be too or a name resolves on
        // hover while never appearing in completion (RFC 1 §4.2).
        return $reflection->isInternal() ? FunctionInfo::fromReflection($reflection) : null;
    }
}
