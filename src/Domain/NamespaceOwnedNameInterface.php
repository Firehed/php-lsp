<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * A name that lives directly under a namespace: a class-like, a function, or a
 * free-standing constant. The {@see QualifiedName} is the canonical path to the
 * name components; the specific type carries the {@see NameKind}.
 */
interface NamespaceOwnedNameInterface
{
    public QualifiedName $qualifiedName { get; }
}
