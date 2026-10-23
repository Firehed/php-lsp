<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Resolution\ExpressionResolverInterface;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use Firehed\PhpLsp\Resolution\Scope;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

final class SymbolAtPosition implements SymbolAtPositionInterface
{
    public function __construct(
        private readonly PositionLocatorInterface $locator,
        private readonly ExpressionResolverInterface $expressions,
        private readonly SymbolSourceInterface $symbols,
    ) {
    }

    public function symbolAt(TextDocument $document, ByteOffset $offset): ?ResolvedSymbolInterface
    {
        $located = $this->locator->locate($document, $offset);
        $written = $located->written;
        $scope = $located->scope;
        $parent = $written?->getAttribute('parent');

        return match ($located->slot) {
            Slot::NotCode, Slot::InterpolatedString, Slot::ClassMember => null,
            Slot::Variable => $this->variable($written, $located->parsed),
            Slot::Member, Slot::StaticMember, Slot::CalledName => $this->usedBy($written, $located->parsed),
            Slot::ArgumentName => $this->parameterNamed($located),
            Slot::DeclaredName => $parent instanceof Node\Param
                ? $this->expressions->resolveParameter($parent, $located->parsed)
                : null,
            Slot::Expression => $parent instanceof Expr\ConstFetch
                ? $this->expressions->resolve($parent, $located->parsed)
                : $this->classLike($written, $scope),
            Slot::StaticReceiver, Slot::ParameterType, Slot::ReturnType, Slot::PropertyType, Slot::AfterModifiers,
            Slot::Catch, Slot::Instanceof, Slot::New, Slot::Implements, Slot::ClassExtends, Slot::InterfaceExtends,
            Slot::Attribute, Slot::TraitUse, Slot::Import => $this->classLike($written, $scope),
        };
    }

    /**
     * Nothing is written for a variable whose name is an expression.
     */
    private function variable(?Node $written, ParsedDocument $parsed): ?ResolvedSymbolInterface
    {
        if ($written === null) {
            return null;
        }
        assert($written instanceof Expr\Variable && is_string($written->name), 'a written variable is named');
        return $this->expressions->resolve($written, $parsed) ?? new ResolvedVariable($written->name, null);
    }

    /**
     * What the expression a member or called name is part of resolves to.
     */
    private function usedBy(?Node $written, ParsedDocument $parsed): ?ResolvedSymbolInterface
    {
        if ($written === null) {
            return null;
        }
        $expression = $written->getAttribute('parent');
        assert($expression instanceof Expr, 'a member or called name is part of the expression that uses it');
        return $this->expressions->resolve($expression, $parsed);
    }

    private function parameterNamed(Located $located): ?ResolvedSymbolInterface
    {
        assert($located->call !== null, 'an argument\'s name is in its call\'s argument list');
        assert($located->written instanceof Identifier, 'an argument\'s name is written');
        return $this->expressions->resolveCallable($located->call->call, $located->parsed)
            ?->getParameterByName($located->written->toString());
    }

    /**
     * A name where a class-like may be named. A type that is not a class-like,
     * such as `int`, is an identifier.
     */
    private function classLike(?Node $written, Scope $scope): ?ResolvedSymbolInterface
    {
        if (!$written instanceof Name) {
            return null;
        }
        $name = $scope->resolveClasslikeName($written);
        return $name === null ? null : $this->symbols->lookupClassLike(ClasslikeName::fromFullyQualified($name));
    }
}
