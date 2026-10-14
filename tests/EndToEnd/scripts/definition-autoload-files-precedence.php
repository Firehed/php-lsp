<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$consumer = 'src/Definition/AutoloadPrecedence.php';

return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($consumer),
        new Step\Definition(
            $consumer,
            new Marker\SymbolMarker('files_entry_class'),
            expect: new Expectation\LandsOn('AutoloadFiles/precedence.php', line: 11),
        ),
    ],
);
