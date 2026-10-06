<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/Repository/ClassInfoPatterns.php';

$hover = fn (string $marker, Expectation\HoverExpectationInterface ...$expect): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover($file, new Marker\SymbolMarker($marker), expect: array_values($expect)),
    ],
);

return [
    'variadic' => $hover('variadic_param', new Expectation\Shows('...$items')),
    // A default value is shown as written, and a required parameter shows none.
    'optional' => $hover(
        'optional_param',
        new Expectation\Shows('$count = 0'),
        new Expectation\Hides('$name = ...'),
    ),
];
