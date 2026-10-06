<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'SignatureHelp.php';

$signatureHelp = fn (
    string $marker,
    Expectation\SignatureHelpExpectationInterface ...$expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\SignatureHelp($file, new Marker\CursorMarker($marker), expect: array_values($expect)),
    ],
);

return [
    'user-defined function' => $signatureHelp(
        'first_param',
        new Expectation\SignatureCount(1),
        new Expectation\SignatureShows('signatureHelpAdd', 'int $a'),
        new Expectation\ActiveParameter(0),
        new Expectation\DocumentationShows('Adds two numbers'),
    ),
    'second parameter' => $signatureHelp(
        'second_param',
        new Expectation\SignatureShows('signatureHelpAdd'),
        new Expectation\ActiveParameter(1),
    ),
    'built-in function' => $signatureHelp('builtin', new Expectation\SignatureShows('array_map')),
    'outside a call' => $signatureHelp('outside_call', new Expectation\NoAnswer()),
];
