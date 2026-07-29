<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Stmt\Use_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The RFC 1 §8.1 mechanism for §4.2 (Symbol Discovery Authority): symbol existence,
 * metadata, and namespace enumeration are answered through the SymbolSource /
 * SymbolSink seam, so the concrete index, repository, autoload map, and reflection
 * that back it are reachable only within a backend.
 *
 * A consumer that imports one of those collaborators directly is querying a concrete
 * store instead of the seam — the coupling §4.2 forbids, and the coupling the Step 2
 * migration (Plan 0002 §5.5) removed from `ClassCandidates`, `NamespaceCandidates`,
 * `SymbolResolver`, and `TextDocumentSyncHandler`.
 *
 * @implements Rule<Use_>
 */
final class SymbolDiscoveryAuthorityRule implements Rule
{
    /**
     * The symbol-discovery collaborators §4.2 confines to a backend: a concrete index,
     * repository, autoload map, and reflection (`ReflectionClass` is a global class, so
     * it has no namespace prefix).
     *
     * @var list<string>
     */
    private const array CONFINED_COLLABORATORS = [
        'Firehed\PhpLsp\Index\ComposerAutoloadMap',
        'Firehed\PhpLsp\Index\NamespaceCatalog',
        'Firehed\PhpLsp\Index\SymbolIndex',
        'Firehed\PhpLsp\Repository\ClassRepository',
        'Firehed\PhpLsp\Repository\DefaultClassRepository',
        'Firehed\PhpLsp\Repository\DefaultFunctionRepository',
        'Firehed\PhpLsp\Repository\FunctionRepository',
        'ReflectionClass',
    ];

    /**
     * The function/constant path is still served by `FunctionRepository` until Step 3
     * gives `lookupFunction` / `lookupConstant` real project reach (Plan 0002 §5.5,
     * §5.7). This is a temporary exemption, REMOVED in Step 3 — not a permanent
     * carve-out — so the two names sit in the confined set above and are exempted here
     * rather than simply omitted.
     *
     * @var list<string>
     */
    private const array FUNCTION_PATH_EXEMPTION = [
        'Firehed\PhpLsp\Repository\DefaultFunctionRepository',
        'Firehed\PhpLsp\Repository\FunctionRepository',
    ];

    /**
     * The composition root (Server) wires the concrete collaborators into the backend,
     * so the root namespace names them directly.
     */
    private const string COMPOSITION_ROOT_NAMESPACE = 'Firehed\PhpLsp';

    /**
     * The backend packages (RFC 1 §5.3) are where the collaborators legitimately
     * compose, and tests are not production consumers — the parity suites use
     * reflection as the §4.7 oracle by design. A namespace equal to or nested under one
     * of these is exempt.
     *
     * @var list<string>
     */
    private const array EXEMPT_NAMESPACE_PREFIXES = [
        'Firehed\PhpLsp\Index',
        'Firehed\PhpLsp\Knowledge',
        'Firehed\PhpLsp\Repository',
        'Firehed\PhpLsp\Tests',
    ];

    public function getNodeType(): string
    {
        return Use_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->isBackendNamespace($scope->getNamespace())) {
            return [];
        }

        $errors = [];
        foreach ($node->uses as $use) {
            $name = $use->name->toString();
            if (!in_array($name, self::CONFINED_COLLABORATORS, true)) {
                continue;
            }
            if (in_array($name, self::FUNCTION_PATH_EXEMPTION, true)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                '%s is a symbol-discovery backend collaborator and must not be used outside a '
                    . 'SymbolSource/SymbolSink backend; depend on the Knowledge seam instead (RFC 1 §4.2).',
                $name,
            ))
                ->identifier('phpLsp.symbolDiscoveryAuthority')
                ->line($use->getStartLine())
                ->build();
        }

        return $errors;
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
