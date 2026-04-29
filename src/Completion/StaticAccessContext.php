<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use PhpParser\Node\Name;

final class StaticAccessContext
{
    public function __construct(
        public readonly CompletionContext $context,
        public readonly Name $class,
        public readonly string $prefix,
    ) {
    }
}
