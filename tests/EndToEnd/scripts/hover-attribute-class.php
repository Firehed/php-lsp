<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/Services/ApiController.php';

return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover(
            $file,
            new Marker\SymbolMarker('attr_class'),
            expect: new Expectation\Shows('Route', 'Defines a route'),
        ),
    ],
);
