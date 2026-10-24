<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Resolution\ExpressionResolverInterface;
use Firehed\PhpLsp\Resolution\VariableBindings;

final class ScopeAtPosition implements ScopeAtPositionInterface
{
    public function __construct(
        private readonly PositionLocatorInterface $locator,
        private readonly ExpressionResolverInterface $expressions,
    ) {
    }

    public function variablesAt(TextDocument $document, ByteOffset $offset): array
    {
        $located = $this->locator->locate($document, $offset);
        $nearest = VariableBindings::visible($located->scope, $offset->value);

        $variables = [];
        $thisVariable = $this->expressions->resolveThis($located->scope);
        if ($thisVariable !== null) {
            $variables[] = $thisVariable;
        }
        foreach ($nearest as $binding) {
            $variables[] = $this->expressions->resolveBinding($binding, $located->parsed);
        }
        return $variables;
    }
}
