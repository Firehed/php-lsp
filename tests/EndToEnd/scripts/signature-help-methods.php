<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$calls = 'SignatureHelp.php';
$user = 'src/Domain/User.php';
$child = 'src/Inheritance/ChildClass.php';

$signatureHelp = fn (
    string $file,
    string $marker,
    Expectation\SignatureHelpExpectationInterface ...$expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\SignatureHelp($file, new Marker\CursorMarker($marker), expect: array_values($expect)),
    ],
);

$setName = [
    new Expectation\SignatureShows('setName'),
    new Expectation\DocumentationShows("Updates the user's display name"),
];

return [
    'method' => $signatureHelp($user, 'sig_this_call', ...$setName),
    'nullsafe method call' => $signatureHelp($user, 'sig_nullsafe_property', ...$setName),
    'typed variable method call' => $signatureHelp($calls, 'typed_param', ...$setName),
    'assigned variable method call' => $signatureHelp($calls, 'assigned_var', ...$setName),
    'nullsafe typed variable method call' => $signatureHelp($calls, 'nullsafe_param', ...$setName),
    'static method' => $signatureHelp($calls, 'static_call', new Expectation\SignatureShows('fromScore', 'int $score')),
    'self static method' => $signatureHelp(
        $user,
        'sig_self_call',
        new Expectation\SignatureShows('create', 'string $id'),
    ),
    'self static call' => $signatureHelp($child, 'self_sig', new Expectation\SignatureShows('staticMethod')),
    'static static call' => $signatureHelp($child, 'static_sig', new Expectation\SignatureShows('staticMethod')),
    'incomplete code' => $signatureHelp(
        'src/IncompleteCode/SingleIncompleteSigHelp.php',
        'sig_this_call',
        new Expectation\SignatureShows('getName'),
    ),
];
