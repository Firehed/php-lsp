<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Repository\DefaultFunctionRepository;
use Firehed\PhpLsp\Repository\FunctionRepository;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\ClassNameUsageLocation;
use PHPStan\Rules\RestrictedUsage\RestrictedClassNameUsageExtension;
use PHPStan\Rules\RestrictedUsage\RestrictedUsage;
use ReflectionClass;

/**
 * The RFC 1 §8.1 mechanism for §4.2 (Symbol Discovery Authority): symbol existence,
 * metadata, and namespace enumeration are answered through the SymbolSource /
 * SymbolSink seam, so the concrete index, repository, autoload map, and reflection
 * that back it are reachable only within a backend.
 *
 * §4.2 forbids the *dependency*, not a particular spelling of it. A
 * `RestrictedClassNameUsageExtension` is the seam PHPStan provides for exactly this:
 * PHPStan resolves every class-name reference — an `use` import, a group-use, a
 * fully-qualified name, a `new`, a static call, an `instanceof`, an `extends` /
 * `implements`, a parameter/property/return type, an attribute — to a
 * `ClassReflection` and asks whether that usage is allowed. The rule therefore states
 * only the policy (which classes are confined, which namespaces compose them); the
 * framework owns detection, so no spelling can slip past.
 *
 * That coupling is the one §4.2 forbids, and the one the Step 2 migration (Plan 0002
 * §5.5) removed from `ClassCandidates`, `NamespaceCandidates`, `SymbolResolver`, and
 * `TextDocumentSyncHandler`.
 */
final class SymbolDiscoveryAuthorityExtension implements RestrictedClassNameUsageExtension
{
    /**
     * The symbol-discovery collaborators §4.2 confines to a backend: a concrete index,
     * autoload map, the function repository (the un-migrated function path, exempted
     * below until Step 3b), and reflection (`ReflectionClass` is a global class, so it
     * has no namespace prefix). Class-like lookup is now served entirely by the
     * {@see \Firehed\PhpLsp\Knowledge\SymbolBackend}s, so `ClassRepository` is gone.
     *
     * Adding an entry tightens. Removing one loosens (human only). See
     * docs/architecture/enforcement-edits.md.
     *
     * @var list<class-string>
     */
    private const array CONFINED_COLLABORATORS = [
        ComposerAutoloadMap::class,
        NamespaceCatalog::class,
        SymbolIndex::class,
        DefaultFunctionRepository::class,
        FunctionRepository::class,
        ReflectionClass::class,
    ];

    /**
     * The function/constant path is still served by `FunctionRepository` until Step 3
     * gives `lookupFunction` / `lookupConstant` real project reach (Plan 0002 §5.5,
     * §5.7). This is a temporary exemption, REMOVED in Step 3 — not a permanent
     * carve-out — so the two names sit in the confined set above and are exempted here
     * rather than simply omitted.
     *
     * Adding an entry loosens (human only). Removing one tightens.
     *
     * @var list<class-string>
     */
    private const array FUNCTION_PATH_EXEMPTION = [
        DefaultFunctionRepository::class,
        FunctionRepository::class,
    ];

    /**
     * The composition root (Server) wires the concrete collaborators into the backend,
     * so the root namespace names them directly.
     *
     * Changing this loosens (human only).
     */
    private const string COMPOSITION_ROOT_NAMESPACE = 'Firehed\PhpLsp';

    /**
     * The backend packages (RFC 1 §5.3) are where the collaborators legitimately
     * compose, and tests are not production consumers — the parity suites use
     * reflection as the §4.7 oracle by design. A namespace equal to or nested under one
     * of these is exempt.
     *
     * Adding an entry loosens (human only). Removing one tightens.
     *
     * @var list<string>
     */
    private const array EXEMPT_NAMESPACE_PREFIXES = [
        'Firehed\PhpLsp\Index',
        'Firehed\PhpLsp\Knowledge',
        'Firehed\PhpLsp\Repository',
        'Firehed\PhpLsp\Tests',
    ];

    public function isRestrictedClassNameUsage(
        ClassReflection $classReflection,
        Scope $scope,
        ClassNameUsageLocation $location,
    ): ?RestrictedUsage {
        $name = $classReflection->getName();
        if (!in_array($name, self::CONFINED_COLLABORATORS, true)) {
            return null;
        }
        if (in_array($name, self::FUNCTION_PATH_EXEMPTION, true)) {
            return null;
        }
        if ($this->isBackendNamespace($scope->getNamespace())) {
            return null;
        }

        return RestrictedUsage::create(
            sprintf(
                '%s is a symbol-discovery backend collaborator and must not be referenced outside a '
                    . 'SymbolSource/SymbolSink backend; depend on the Knowledge seam instead (RFC 1 §4.2).',
                $name,
            ),
            'phpLsp.symbolDiscoveryAuthority',
        );
    }

    private function isBackendNamespace(?string $namespace): bool
    {
        // A file with no namespace declaration (global-namespace code) is not a
        // backend; normalising to '' matches neither the root nor a prefix below.
        $namespace ??= '';

        if ($namespace === self::COMPOSITION_ROOT_NAMESPACE) {
            return true;
        }

        foreach (self::EXEMPT_NAMESPACE_PREFIXES as $prefix) {
            if ($namespace === $prefix || str_starts_with($namespace, $prefix . '\\')) {
                return true;
            }
        }

        return false;
    }
}
