<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolverInterface;
use Firehed\PhpLsp\Resolution\TypeSource\TypeSourceInterface;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Error;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Detects member-access context at a cursor position.
 *
 * Walks the tree the {@see SyntaxSourceInterface} composite returns. A cursor over
 * broken text lands on a node the cursor-text source synthesizes,
 * so instance and static access resolve through the same branches as
 * a real AST node — no separate text path. One
 * {@see self::visibilityBetween()} function decides the visibility a vantage
 * class has toward a target class, so instance and static branches cannot
 * disagree.
 *
 * @internal
 */
final class MemberAccessDetector
{
    public function __construct(
        private readonly SymbolSourceInterface $symbolSource,
        private readonly MemberResolverInterface $memberResolver,
        private readonly TypeSourceInterface $typeSource,
        private readonly SyntaxSourceInterface $parser,
    ) {
    }

    /**
     * @param array<Stmt> $ast
     */
    public function detect(
        TextDocument $document,
        array $ast,
        int $line,
        int $character,
    ): ?MemberAccessContext {
        $offset = $document->offsetAt($line, $character);

        $node = $this->parser->nodeAt($ast, $document, $offset > 0 ? $offset - 1 : 0);

        if ($node === null) {
            return null;
        }

        if ($node instanceof Identifier || $node instanceof Error) {
            $parent = $node->getAttribute('parent');
            if ($parent instanceof Node) {
                $node = $parent;
            } else {
                // @codeCoverageIgnoreStart
                throw new LogicException('Node missing parent attribute');
                // @codeCoverageIgnoreEnd
            }
        }

        if (self::isInstanceAccess($node)) {
            /** @var MethodCall|NullsafeMethodCall|PropertyFetch|NullsafePropertyFetch $node */
            if (
                ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
                && $node->name instanceof Identifier
            ) {
                $nameEndPos = $node->name->getEndFilePos();
                if ($offset > $nameEndPos + 1) {
                    return null;
                }
            }

            $prefix = $node->name instanceof Identifier ? $node->name->toString() : '';
            $type = $this->expressionResolver($document)->resolve($node->var, $ast)?->getType();
            $vantage = self::vantageFor($node);
            $visibility = $this->visibilityForReceiver($vantage, $type);
            if ($type !== null && $visibility !== null) {
                return MemberAccessContext::forInstance($type, $visibility, $prefix);
            }
            return null;
        }

        if ($node instanceof StaticPropertyFetch || $node instanceof StaticCall || $node instanceof ClassConstFetch) {
            if ($node instanceof StaticCall && $node->name instanceof Identifier) {
                $nameEndPos = $node->name->getEndFilePos();
                if ($offset > $nameEndPos + 1) {
                    return null;
                }
            }
            return $this->resolveStaticAccessContext($node);
        }

        return null;
    }

    /**
     * The enclosing class-like of the access site.
     */
    private static function vantageFor(Node $node): ?ClasslikeName
    {
        $enclosingName = ScopeFinder::findEnclosingClasslikeName($node);
        return $enclosingName !== null ? ClasslikeName::fromFullyQualified($enclosingName) : null;
    }

    private function expressionResolver(TextDocument $document): ExpressionResolver
    {
        return new ExpressionResolver(
            $this->memberResolver,
            $this->symbolSource,
            $this->typeSource,
            $document,
        );
    }

    /**
     * Null when the receiver resolves to no classes; otherwise the most
     * restrictive visibility across the constituents — a member must be
     * visible on every possible runtime class to be safe to offer.
     */
    private function visibilityForReceiver(?ClasslikeName $vantage, ?TypeInterface $type): ?Visibility
    {
        $classes = ExpressionResolver::receiverClasslikeNames($type);
        if ($classes === []) {
            return null;
        }
        $visibility = Visibility::Private;
        foreach ($classes as $target) {
            $per = $this->visibilityBetween($vantage, $target);
            if ($per->value > $visibility->value) {
                $visibility = $per;
            }
            if ($visibility === Visibility::Public) {
                break;
            }
        }
        return $visibility;
    }

    /**
     * The one function that decides how visible a target class is to a vantage
     * class. Same class: private. Subclass (any depth): protected. Otherwise
     * (or no vantage): public. Every call site — instance and static — routes
     * through this function, so the branches cannot disagree on which members
     * a position may see.
     */
    private function visibilityBetween(?ClasslikeName $vantage, ClasslikeName $target): Visibility
    {
        if ($vantage === null) {
            return Visibility::Public;
        }
        if ($vantage->equals($target)) {
            return Visibility::Private;
        }
        if ($this->memberResolver->isSubclassOf($vantage, $target)) {
            return Visibility::Protected;
        }
        return Visibility::Public;
    }

    private function resolveStaticAccessContext(
        StaticPropertyFetch|StaticCall|ClassConstFetch $node,
    ): ?MemberAccessContext {
        $class = $node->class;
        if (!$class instanceof Name) {
            return null;
        }

        $prefix = $node->name instanceof Identifier ? $node->name->toString() : '';
        $rawName = $class->toString();
        $keyword = LateBindingKeyword::tryFromName($rawName);
        $enclosingClassLike = ScopeFinder::findEnclosingClassNode($node);
        $enclosingName = LateBindingKeyword::Self->resolveIn($enclosingClassLike);
        $vantage = $enclosingName !== null ? ClasslikeName::fromFullyQualified($enclosingName) : null;

        if ($keyword === LateBindingKeyword::Parent) {
            $parentClasslikeName = $keyword->resolveIn($enclosingClassLike);
            if ($parentClasslikeName === null) {
                return null;
            }
            $targetName = ClasslikeName::fromFullyQualified($parentClasslikeName);
            return MemberAccessContext::forParent(
                new ClasslikeType($targetName),
                $this->visibilityBetween($vantage, $targetName),
                $prefix,
            );
        }

        if ($keyword === LateBindingKeyword::Self || $keyword === LateBindingKeyword::Static) {
            if ($enclosingName === null) {
                return null;
            }
            $targetName = ClasslikeName::fromFullyQualified($enclosingName);
            return MemberAccessContext::forStatic(
                new ClasslikeType($targetName),
                $this->visibilityBetween($vantage, $targetName),
                $prefix,
            );
        }

        /** @var class-string $rawName */
        $targetName = ClasslikeName::fromFullyQualified($rawName);
        return MemberAccessContext::forStatic(
            new ClasslikeType($targetName),
            $this->visibilityBetween($vantage, $targetName),
            $prefix,
        );
    }

    private static function isInstanceAccess(Node $node): bool
    {
        return $node instanceof MethodCall
            || $node instanceof NullsafeMethodCall
            || $node instanceof PropertyFetch
            || $node instanceof NullsafePropertyFetch;
    }
}
