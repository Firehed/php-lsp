<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$consumer = 'src/Definition/AutoloadPrecedence.php';

// Composer requires every `files` entry before the autoloader is asked, so a
// class declared there wins over the same name under a PSR-4 directory.
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
