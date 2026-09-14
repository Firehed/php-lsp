<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

return [
    CodeResolverInterface::class => SymbolResolver::class,

    SymbolResolver::class,

    TypeSource\TypeSourceInterface::class => TypeSource\NativeTypeSource::class,
    TypeSource\NativeTypeSource::class,
];
