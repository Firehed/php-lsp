<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/Completion/StaticCaller.php';

// From an unrelated class, only public static members are offered.
return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Complete($file, new Marker\CursorMarker('external_static'), expect: [
            new Expectation\Offers('create', 'getInstance', 'NAME', 'class'),
            new Expectation\Withholds('reset', 'INTERNAL', 'SECRET'),
        ]),
    ],
);
