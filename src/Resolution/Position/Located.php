<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Parser\ParsedDocument;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * What a document's structure has at a position, read once, for the position
 * services in this namespace to answer from. It does not leave this namespace.
 */
final readonly class Located
{
    /**
     * @param string $word The word written in the slot, or empty where none is
     * @param string $prefix The part of the word before the position
     * @param ?Node $written The node for the word
     * @param Expr|Name|null $receiver What a member is accessed on: set exactly
     *        when the slot is {@see Slot::Member} or {@see Slot::StaticMember}
     * @param list<Stmt\Function_|Stmt\ClassMethod|Expr\Closure|Expr\ArrowFunction> $functionLikes Outermost first
     */
    public function __construct(
        public ParsedDocument $parsed,
        public ByteOffset $offset,
        public Slot $slot,
        public string $word,
        public string $prefix,
        public ?Node $written,
        public Expr|Name|null $receiver,
        public ?Stmt\Namespace_ $namespace,
        public Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null $classLike,
        public array $functionLikes,
        public ?CallSite $call,
    ) {
    }
}
