<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Resolution\ExpressionResolverInterface;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
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
        $scope = $located->scope;

        $nearest = [];
        foreach (VariableBindings::before($scope, $offset->value) as $binding) {
            $nearest[$binding->name] = $binding;
        }

        $variables = [];
        $thisClass = $scope->getThisType();
        if ($thisClass !== null) {
            $variables[] = new ResolvedVariable('this', new ClasslikeType($thisClass));
            unset($nearest['this']);
        }
        foreach ($nearest as $binding) {
            $variables[] = $this->expressions->resolveBinding($binding, $scope, $located->parsed);
        }
        return $variables;
    }
}
