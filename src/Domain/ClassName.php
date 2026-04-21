<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Type-safe wrapper for fully-qualified class names.
 */
final readonly class ClassName
{
    /**
     * @param class-string $fqn
     */
    public function __construct(
        public string $fqn,
    ) {
    }

    public function shortName(): string
    {
        $lastSeparator = strrpos($this->fqn, '\\');
        if ($lastSeparator === false) {
            return $this->fqn;
        }
        return substr($this->fqn, $lastSeparator + 1);
    }

    public function namespace(): ?string
    {
        $lastSeparator = strrpos($this->fqn, '\\');
        if ($lastSeparator === false) {
            return null;
        }
        return substr($this->fqn, 0, $lastSeparator);
    }

    public function equals(self $other): bool
    {
        return strcasecmp($this->fqn, $other->fqn) === 0;
    }
}
