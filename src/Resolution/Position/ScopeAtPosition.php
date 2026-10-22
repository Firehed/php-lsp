<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Resolution\ExpressionResolverInterface;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\NameContextFactory;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use Firehed\PhpLsp\Resolution\Scope;
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
        $scope = self::scopeOf($located);

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

    public function nameContextAt(TextDocument $document, ByteOffset $offset): NameContext
    {
        $located = $this->locator->locate($document, $offset);
        return NameContextFactory::in($located->namespace, $located->parsed->tree);
    }

    /**
     * The scope of the innermost function-like holding the position, or the
     * global scope of its namespace or file.
     */
    private static function scopeOf(Located $located): Scope
    {
        $function = $located->functionLikes === [] ? null : $located->functionLikes[count($located->functionLikes) - 1];
        if ($function !== null) {
            return Scope::forNode($function, $located->classLike);
        }
        return Scope::global($located->namespace->stmts ?? $located->parsed->tree, $located->classLike);
    }
}
