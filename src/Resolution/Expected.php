<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\KeywordGroup;
use Firehed\PhpLsp\Domain\TypeHintContext;

/**
 * What may be written at a position. Each field is one kind of thing; one that
 * the position does not admit is null or false.
 */
final readonly class Expected
{
    /**
     * @param string $prefix What is written at the position, up to it
     */
    public function __construct(
        public string $prefix,
        public ?MemberAccessContext $members = null,
        public ?CallContext $argumentNames = null,
        public bool $variables = false,
        public ?KeywordGroup $keywords = null,
        public ?TypeHintContext $builtinTypes = null,
        public ?NameQuery $names = null,
    ) {
    }
}
