<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'SignatureHelp.php';

$hover = fn (string $marker, Expectation\HoverExpectationInterface $expect): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover($file, new Marker\SymbolMarker($marker), expect: $expect),
    ],
);

$setName = new Expectation\Shows('setName', 'Updates the user');

return [
    'function' => $hover(
        'signatureHelpAdd',
        new Expectation\Shows('signatureHelpAdd', 'int', 'Adds two numbers together'),
    ),
    'class with docblock' => $hover('class_instantiation', new Expectation\Shows('User', 'Represents a system user')),
    'typed variable method call' => $hover('typedVarMethod', $setName),
    'assigned variable method call' => $hover('assignedVarMethod', $setName),
    'nullsafe typed variable method call' => $hover('nullsafeTypedVar', $setName),
];
