<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

/**
 * Why a {@see Reference} resolves at the cursor — or that it does not.
 *
 * Completion sort order is defined by {@see self::priority()}: nearer references
 * outrank farther ones so bare-name matches survive the response cap when a
 * wide prefix would otherwise let long-qualified names crowd them out.
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

    public function priority(): int
    {
        return match ($this) {
            self::CurrentNamespace => 0,
            self::Import => 1,
            self::PrefixImport => 2,
            self::GlobalFallback => 3,
            self::SubNamespace => 4,
            self::Unreachable => 5,
        };
    }
}
