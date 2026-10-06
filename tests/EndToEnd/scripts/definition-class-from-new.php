<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'SignatureHelp.php';

return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Definition(
            $file,
            new Marker\SymbolMarker('class_instantiation'),
            expect: new Expectation\LandsOn('src/Domain/User.php', line: 15),
        ),
    ],
);
