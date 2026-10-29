<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Resolution\MemberAccess;
use Firehed\PhpLsp\Resolution\Scope;
use PhpParser\Node;

/**
 * What a document's structure has at a position, read once, for the position
 * services in this namespace to answer from. It does not leave this namespace.
 */
final readonly class Located
{
    /**
     * @param string $prefix The part of a word written before the position
     * @param ?Node $written The node for the word
     * @param ?MemberAccess $access The member access whose name is at the
     *        position: set exactly when the slot is {@see Slot::Member}
     */
    public function __construct(
        public ParsedDocument $parsed,
        public ByteOffset $offset,
        public Slot $slot,
        public string $prefix,
        public ?Node $written,
        public ?MemberAccess $access,
        public Scope $scope,
        public ?CallSite $call,
    ) {
    }
}
