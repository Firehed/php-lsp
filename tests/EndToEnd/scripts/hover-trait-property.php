<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/Traits/HasTimestamps.php';

return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover(
            $file,
            new Marker\SymbolMarker('trait_property'),
            expect: new Expectation\Shows('$displayName', 'Display name for the entity'),
        ),
    ],
);
