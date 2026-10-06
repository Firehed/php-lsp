<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/Resolution/NamespacedConstant.php';

$hover = fn (string $marker, Expectation\HoverExpectationInterface $expect): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover($file, new Marker\SymbolMarker($marker), expect: $expect),
    ],
);

return [
    // An unqualified constant in a namespace is looked up in that namespace first,
    'namespaced' => $hover('namespaced_const_fetch', new Expectation\Shows('NAMESPACED_CONSTANT')),
    // and falls back to the global one when the namespace does not define it.
    'global fallback' => $hover('global_const_fetch', new Expectation\Shows('FIXTURE_HELPER_DEFINED')),
];
