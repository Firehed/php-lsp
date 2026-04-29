<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use PhpParser\Node\Expr;

final class MemberAccessContext
{
    public function __construct(
        public readonly CompletionContext $context,
        public readonly Expr $var,
        public readonly string $prefix,
    ) {
    }
}
