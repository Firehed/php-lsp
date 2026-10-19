<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$user = 'src/Domain/User.php';

// Each marker is the start of a called member's name, before its `(`: no
// argument is being typed there ([LSP] textDocument/signatureHelp).
$onName = fn (string $marker): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($user),
        new Step\SignatureHelp($user, new Marker\SymbolMarker($marker), expect: new Expectation\NoAnswer()),
    ],
);

return [
    'an instance method' => $onName('setName'),
    'a static method' => $onName('create'),
    'a trait method' => $onName('markCreated'),
];
