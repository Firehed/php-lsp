<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use Firehed\PhpLsp\Utility\NamespacePath;

/**
 * A fully-qualified name split into namespace path and short name, carrying no
 * notion of what it names (Plan 0002 §5.3: the kind-neutral base FQN value type).
 * A name alone does not identify a symbol — PHP has three symbol namespaces — so a
 * {@see \Firehed\PhpLsp\Resolution\NameKind} travels alongside it.
 *
 * The global namespace is the empty path.
 */
final readonly class QualifiedName
{
    public function __construct(
        public string $namespace,
        public string $shortName,
    ) {
    }

    /**
     * A leading `\` is spelling rather than identity, so it is dropped.
     */
    public static function fromFullyQualified(string $fullyQualifiedName): self
    {
        $fqn = ltrim($fullyQualifiedName, '\\');

        return new self(NamespacePath::namespaceOf($fqn), NamespacePath::shortNameOf($fqn));
    }

    public function fullyQualifiedName(): string
    {
        return NamespacePath::join($this->namespace, $this->shortName);
    }
}
