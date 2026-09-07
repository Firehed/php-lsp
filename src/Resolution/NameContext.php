<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespacePath;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\NameContext as PhpParserNameContext;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Use_;

/**
 * The name-resolution context at a cursor: the enclosing namespace and the
 * import tables in effect.
 *
 * PHP keeps three separate import tables (`use`, `use function`, `use const`),
 * and consults a different one depending on the kind of the name being
 * resolved — so they cannot be collapsed into a single map. See
 * {@see ReferenceResolver} for how each is used.
 *
 * Each table maps the short name (or alias) to the fully qualified name it
 * binds, without a leading separator.
 *
 * The resolution rules themselves — namespace prefixing, alias lookup, and
 * the function/constant global fallback (PHP manual, name resolution rules
 * 5–7) — are delegated to php-parser's own {@see PhpParserNameContext}
 * (build-manifest step-44). The tables above stay public because
 * {@see \Firehed\PhpLsp\Completion\SymbolCandidates} and
 * {@see ReferenceResolver} enumerate them, which php-parser's engine does not
 * expose.
 */
final readonly class NameContext
{
    /**
     * @param array<string, string> $classImports
     * @param array<string, string> $functionImports
     * @param array<string, string> $constantImports
     */
    public function __construct(
        public string $namespace,
        public array $classImports = [],
        public array $functionImports = [],
        public array $constantImports = [],
    ) {
    }

    /**
     * The FQN candidates for a short name, in resolution order.
     *
     * For class-likes: exactly one candidate (import or namespace-qualified).
     * For functions and constants: namespace-qualified first, then global
     * fallback (PHP manual, name resolution rules 5–7).
     *
     * @return list<string>
     */
    public function candidates(string $short, NameKind $kind): array
    {
        $engine = $this->engine();
        $name = str_starts_with($short, '\\')
            ? new Name\FullyQualified(ltrim($short, '\\'))
            : new Name($short);

        if ($kind === NameKind::ClassLike) {
            return [$engine->getResolvedClassName($name)->toString()];
        }

        $type = $kind === NameKind::Constant ? Use_::TYPE_CONSTANT : Use_::TYPE_FUNCTION;
        $resolved = $engine->getResolvedName($name, $type);
        if ($resolved !== null) {
            return [$resolved->toString()];
        }

        // Rule 7: an unqualified function or constant in a namespace tries the
        // namespaced spelling first, then falls back to global — php-parser
        // reports the ambiguity as a null and leaves the choice to the caller.
        return [NamespacePath::join($this->namespace, $short), $short];
    }

    /**
     * The import table consulted for an *unqualified* name of the given kind.
     *
     * PHP manual, name resolution rule 5.
     *
     * @return array<string, string>
     */
    public function importsFor(NameKind $kind): array
    {
        return match ($kind) {
            NameKind::ClassLike => $this->classImports,
            NameKind::Constant => $this->constantImports,
            NameKind::Function_ => $this->functionImports,
        };
    }

    private function engine(): PhpParserNameContext
    {
        $engine = new PhpParserNameContext(new Collecting());
        $engine->startNamespace($this->namespace !== '' ? new Name($this->namespace) : null);

        foreach ($this->classImports as $alias => $fqn) {
            $engine->addAlias(new Name($fqn), $alias, Use_::TYPE_NORMAL);
        }
        foreach ($this->functionImports as $alias => $fqn) {
            $engine->addAlias(new Name($fqn), $alias, Use_::TYPE_FUNCTION);
        }
        foreach ($this->constantImports as $alias => $fqn) {
            $engine->addAlias(new Name($fqn), $alias, Use_::TYPE_CONSTANT);
        }

        return $engine;
    }
}
