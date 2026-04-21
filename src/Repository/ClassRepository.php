<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;

/**
 * Repository for resolving class metadata from multiple sources.
 */
interface ClassRepository
{
    /**
     * Get class information by fully-qualified name.
     *
     * Resolution order: cache -> open documents -> locate & parse -> reflection
     */
    public function get(ClassName $name): ?ClassInfo;

    /**
     * Update the repository with classes from an open document.
     *
     * @param list<ClassInfo> $classes Classes defined in the document
     */
    public function updateDocument(string $uri, array $classes): void;

    /**
     * Remove all classes associated with a closed document.
     */
    public function removeDocument(string $uri): void;
}
