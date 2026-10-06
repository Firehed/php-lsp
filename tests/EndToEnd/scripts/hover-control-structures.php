<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/IncompleteCode/CompleteInControl.php';

$hover = fn (string $marker, Expectation\HoverExpectationInterface $expect): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover($file, new Marker\SymbolMarker($marker), expect: $expect),
    ],
);

return [
    'property in an if condition' => $hover('prop_in_if', new Expectation\Shows('$name', 'string')),
    'method in a while condition' => $hover('method_in_while', new Expectation\Shows('getName')),
];
