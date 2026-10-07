<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/Completion/ResponseCap.php';

return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Complete($file, new Marker\CursorMarker('response_cap'), expect: [
            new Expectation\ReportsIncomplete(),
            new Expectation\Offers('summarizeTotals'),
        ]),
    ],
);
