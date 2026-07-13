<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

/**
 * Why a {@see Reference} resolves at the cursor — or that it does not.
 *
 * The cases are ordered from nearest to furthest, which is also their ranking
 * order in completion: a symbol usable as a bare name outranks one that needs a
 * qualified reference, which outranks one that cannot be referenced at all
 * without qualifying it or adding an import.
 */
enum ReferenceKind
{
    /** Declared in the cursor's own namespace; the last segment resolves. */
    case CurrentNamespace;

    /** Bound directly by a `use` / `use function` / `use const` import. */
    case Import;

    /** Reached through an import of one of its parent namespaces, e.g. `User\Repository`. */
    case PrefixImport;

    /** In a sub-namespace of the cursor's namespace; a relative qualified name resolves. */
    case SubNamespace;

    /** A global function or constant, reached by PHP's runtime fallback. */
    case GlobalFallback;

    /** Not referenceable here without a leading `\` or an added import. */
    case Unreachable;
}
