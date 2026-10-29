<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\LateBindingKeyword;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

/**
 * A member access as written: `->`, `?->`, or `::` with what it is accessed on
 * ($receiver) and the member's name.
 */
final readonly class MemberAccess
{
    private function __construct(
        public Expr\MethodCall|Expr\NullsafeMethodCall|Expr\PropertyFetch|Expr\NullsafePropertyFetch
        |Expr\StaticCall|Expr\StaticPropertyFetch|Expr\ClassConstFetch $node,
        public Expr|Name $receiver,
        public Identifier|Expr $name,
        public bool $doubleColon,
    ) {
    }

    /**
     * The member access a node is, or null when it is none.
     */
    public static function of(?Node $node): ?self
    {
        return match (true) {
            $node instanceof Expr\MethodCall, $node instanceof Expr\NullsafeMethodCall,
            $node instanceof Expr\PropertyFetch, $node instanceof Expr\NullsafePropertyFetch
                => new self($node, $node->var, $node->name, false),
            $node instanceof Expr\StaticCall, $node instanceof Expr\StaticPropertyFetch,
            $node instanceof Expr\ClassConstFetch
                => new self($node, $node->class, $node->name, true),
            default => null,
        };
    }

    /**
     * How the access is written; null for `::` after an expression rather
     * than a class name.
     */
    public function kind(): ?MemberAccessKind
    {
        if (!$this->doubleColon) {
            return MemberAccessKind::Instance;
        }
        if (!$this->receiver instanceof Name) {
            return null;
        }
        return LateBindingKeyword::tryFromName($this->receiver->toString()) === LateBindingKeyword::Parent
            ? MemberAccessKind::Parent
            : MemberAccessKind::Static;
    }
}
