<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/Enum/Status.php';

return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Definition(
            $file,
            new Marker\SymbolMarker('enum_method'),
            expect: new Expectation\LandsOn($file, line: 17),
        ),
    ],
);
