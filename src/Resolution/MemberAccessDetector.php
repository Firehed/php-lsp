<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolver;
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
 * broken text lands on a node the cursor-text source synthesizes (build-manifest
 * step-40), so instance and static access resolve through the same branches as
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
        private readonly MemberResolver $memberResolver,
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
            $vantage = self::vantageFor($node, $ast);
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
            return $this->resolveStaticAccessContext($node, $ast, $offset);
        }

        return null;
    }

    /**
     * The enclosing class-like of the access site, read from the node's file
     * position through {@see Scope::atOffset}. One route works for both parsed
     * and synthesized nodes (build-manifest step-40).
     *
     * @param array<Stmt> $ast
     */
    private static function vantageFor(Node $node, array $ast): ?ClassName
    {
        $classLike = Scope::atOffset($ast, $node->getStartFilePos())->getEnclosingClassLike();
        $enclosingName = $classLike !== null ? ScopeFinder::getClassLikeName($classLike) : null;
        return $enclosingName !== null ? TypeFactory::className($enclosingName) : null;
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
    private function visibilityForReceiver(?ClassName $vantage, ?Type $type): ?Visibility
    {
        $classes = ExpressionResolver::receiverClassNames($type);
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
    private function visibilityBetween(?ClassName $vantage, ClassName $target): Visibility
    {
        if ($vantage === null) {
            return Visibility::Public;
        }
        if ($vantage->fqn === $target->fqn) {
            return Visibility::Private;
        }
        if ($this->symbolSource->isSubclassOf($vantage, $target)) {
            return Visibility::Protected;
        }
        return Visibility::Public;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveStaticAccessContext(
        StaticPropertyFetch|StaticCall|ClassConstFetch $node,
        array $ast,
        int $offset,
    ): ?MemberAccessContext {
        $class = $node->class;
        if (!$class instanceof Name) {
            return null;
        }

        $prefix = $node->name instanceof Identifier ? $node->name->toString() : '';
        $rawName = $class->toString();
        $keyword = LateBindingKeyword::tryFromName($rawName);
        $enclosingClassLike = Scope::atOffset($ast, $offset)->getEnclosingClassLike();
        $enclosingName = LateBindingKeyword::Self->resolveIn($enclosingClassLike);
        $vantage = $enclosingName !== null ? TypeFactory::className($enclosingName) : null;

        if ($keyword === LateBindingKeyword::Parent) {
            $parentClassName = $keyword->resolveIn($enclosingClassLike);
            if ($parentClassName === null) {
                return null;
            }
            $target = TypeFactory::className($parentClassName);
            return MemberAccessContext::forParent(
                $target,
                $this->visibilityBetween($vantage, $target),
                $prefix,
            );
        }

        if ($keyword === LateBindingKeyword::Self || $keyword === LateBindingKeyword::Static) {
            if ($enclosingName === null) {
                return null;
            }
            $target = TypeFactory::className($enclosingName);
            return MemberAccessContext::forStatic(
                $target,
                $this->visibilityBetween($vantage, $target),
                $prefix,
            );
        }

        $raw = $class->toString();
        if (str_contains($raw, '\\')) {
            // Php-parser's name resolver rewrites imported and same-namespace
            // names in place, so a name with a backslash is already qualified.
            $className = $raw;
        } else {
            // A bare short name here reaches us either because the file is in
            // the global namespace with no import for it, or because the node
            // was synthesized by the cursor-text source (build-manifest step-40)
            // and never went through the name resolver. The name context reads
            // the same imports either way and answers correctly for both.
            $context = NameContextFactory::fromAst($ast, $node->getStartLine() - 1);
            $candidates = $context->candidates($raw, \Firehed\PhpLsp\Domain\NameKind::ClassLike);
            $className = $candidates !== [] ? $candidates[0] : $raw;
        }
        /** @var class-string $className */

        $target = TypeFactory::className($className);
        return MemberAccessContext::forStatic(
            $target,
            $this->visibilityBetween($vantage, $target),
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
