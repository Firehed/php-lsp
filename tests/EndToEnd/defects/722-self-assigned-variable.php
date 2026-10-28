<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/Completion/SelfAssign.php';

return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Complete(
            $file,
            new Marker\CursorMarker('self_assigned'),
            expect: [
                new Expectation\Offers('toUpper'),
                new Expectation\Withholds('getName'),
            ],
        ),
    ],
);
