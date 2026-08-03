<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * A fully-qualified name, split into its namespace path and short name, carrying
 * no notion of *what* it names (Plan 0002 §5.3: the kind-neutral base FQN value
 * type).
 *
 * PHP resolves class-likes, functions, and constants in three separate namespaces,
 * so a name alone does not identify a symbol — the kind travels alongside it as a
 * {@see \Firehed\PhpLsp\Resolution\NameKind}. That is what makes this type the
 * right input to a kind-agnostic query: the caller supplies the name it read out of
 * a syntactic position, plus the kind that position implies.
 *
 * The global namespace is the empty path. A leading `\` is spelling rather than
 * identity, so {@see fromFullyQualified()} drops it; the constructor takes the two
 * parts already separated and so never sees one.
 */
final readonly class QualifiedName
{
    public function __construct(
        public string $namespace,
        public string $shortName,
    ) {
    }

    public static function fromClassName(ClassName $name): self
    {
        return self::fromFullyQualified($name->fqn);
    }

    public static function fromFullyQualified(string $fullyQualifiedName): self
    {
        $fqn = ltrim($fullyQualifiedName, '\\');

        $lastSeparator = strrpos($fqn, '\\');
        if ($lastSeparator === false) {
            return new self('', $fqn);
        }

        return new self(substr($fqn, 0, $lastSeparator), substr($fqn, $lastSeparator + 1));
    }

    public function fullyQualifiedName(): string
    {
        if ($this->namespace === '') {
            return $this->shortName;
        }

        return $this->namespace . '\\' . $this->shortName;
    }
}
