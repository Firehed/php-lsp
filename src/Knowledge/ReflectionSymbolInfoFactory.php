<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\InternalConstantSet;
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
        private InternalConstantSet $constants = new InternalConstantSet(),
    ) {
    }

    public function fromReflection(QualifiedName $name, NameKind $kind): ?SymbolInfo
    {
        return match ($kind) {
            NameKind::ClassLike => $this->classInfo($name),
            NameKind::Constant => $this->constantInfo($name),
            NameKind::Function_ => $this->functionInfo($name),
        };
    }

    private function classInfo(QualifiedName $name): ?SymbolInfo
    {
        $fqn = $name->fullyQualifiedName();

        // Both probes narrow the name to a `class-string`, which is why this
        // kind cannot use the sibling's try/catch. `class_exists` answers for
        // classes and enums; `interface_exists` for interfaces. PHP declares no
        // internal trait, so `trait_exists` is omitted — it could only admit
        // names the `isInternal` guard then rejects.
        if (!class_exists($fqn) && !interface_exists($fqn)) {
            return null;
        }

        $rc = new ReflectionClass($fqn);
        return $rc->isInternal() ? $this->classes->fromReflection($rc) : null;
    }

    private function constantInfo(QualifiedName $name): ?SymbolInfo
    {
        $fqn = $name->fullyQualifiedName();
        if (!$this->constants->contains($fqn)) {
            return null;
        }

        return new ConstantInfo(
            name: new ConstantName($name->shortName),
            visibility: Visibility::Public,
            isFinal: true,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: null,
        );
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
