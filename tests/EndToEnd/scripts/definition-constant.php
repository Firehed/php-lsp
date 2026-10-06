<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/Resolution/NamespacedConstant.php';

$definition = fn (
    string $marker,
    Expectation\DefinitionExpectationInterface $expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Definition($file, new Marker\SymbolMarker($marker), expect: $expect),
    ],
);

return [
    // An unqualified constant in a namespace is looked up in that namespace first,
    'namespaced' => $definition('namespaced_const_fetch', new Expectation\LandsOn($file, line: 13)),
    // and falls back to the global one when the namespace does not define it.
    'global fallback' => $definition(
        'global_const_fetch',
        new Expectation\LandsOn('AutoloadFiles/helpers.php', line: 19),
    ),
];
