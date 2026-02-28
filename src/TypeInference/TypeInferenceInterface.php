<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\TypeInference;

use Firehed\PhpLsp\Document\TextDocument;
use PhpParser\Node\Expr;

/**
 * Interface for type inference services.
 */
interface TypeInferenceInterface
{
    /**
     * Get the type of a variable at a specific line.
     */
    public function getVariableType(TextDocument $document, string $variableName, int $line): ?string;

    /**
     * Get the type of an expression.
     */
    public function getExpressionType(TextDocument $document, Expr $expr, int $line): ?string;

    /**
     * Get the return type of a method.
     */
    public function getMethodReturnType(string $className, string $methodName): ?string;

    /**
     * Get the type of a property.
     */
    public function getPropertyType(string $className, string $propertyName): ?string;

    /**
     * Check if a class exists.
     */
    public function hasClass(string $className): bool;

    /**
     * Invalidate cache for a document.
     */
    public function invalidate(string $uri): void;
}
