<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\Container\TypedContainerInterface as TC;

return [
    PhpParserSyntaxSource::class,
    SkeletonSyntaxSource::class,
    CursorTextSyntaxSource::class,
    CompositeSyntaxSource::class,

    MemoizingSyntaxSource::class => fn (TC $c) => new MemoizingSyntaxSource($c->get(CompositeSyntaxSource::class)),

    SyntaxSourceInterface::class => MemoizingSyntaxSource::class,
    MessageScopedInterface::class => MemoizingSyntaxSource::class,
];
