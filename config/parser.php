<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\Container\TypedContainerInterface as TC;

return [
    CompositeSyntaxSource::class => fn (TC $c) => new CompositeSyntaxSource([
        $c->get(PhpParserSyntaxSource::class),
        $c->get(SkeletonSyntaxSource::class),
        $c->get(CursorTextSyntaxSource::class),
    ]),

    MemoizingSyntaxSource::class => fn (TC $c) => new MemoizingSyntaxSource($c->get(CompositeSyntaxSource::class)),

    SyntaxSourceInterface::class => MemoizingSyntaxSource::class,
    MessageScopedInterface::class => MemoizingSyntaxSource::class,

    PhpParserSyntaxSource::class,
    SkeletonSyntaxSource::class,
    CursorTextSyntaxSource::class,
];
