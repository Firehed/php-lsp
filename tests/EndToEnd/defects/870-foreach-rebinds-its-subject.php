<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/Hover/ForeachElement.php';

return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover(
            $file,
            new Marker\SymbolMarker('foreach_rebinds_subject'),
            expect: new Expectation\Shows('getName'),
        ),
    ],
);
