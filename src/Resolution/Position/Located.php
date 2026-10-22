<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Resolution\Scope;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;

/**
 * What a document's structure has at a position, read once, for the position
 * services in this namespace to answer from. It does not leave this namespace.
 */
final readonly class Located
{
    /**
     * @param string $prefix The part of a word written before the position
     * @param ?Node $written The node for the word
     * @param Expr|Name|null $receiver What a member is accessed on: set exactly
     *        when the slot is {@see Slot::Member} or {@see Slot::StaticMember}
     */
    public function __construct(
        public ParsedDocument $parsed,
        public ByteOffset $offset,
        public Slot $slot,
        public string $prefix,
        public ?Node $written,
        public Expr|Name|null $receiver,
        public Scope $scope,
        public ?CallSite $call,
    ) {
    }
}
