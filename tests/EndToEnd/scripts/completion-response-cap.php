<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/Completion/FunctionCompletion.php';
$cursor = new Marker\CursorMarker('user_function');

// A one-letter prefix matches more built-in functions and constants than the
// response holds. The file's own functions rank ahead of them, so they survive
// the cap even though built-in constant names sort first alphabetically.
return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Type($file, $cursor, ' + c'),
        new Step\Complete($file, $cursor, expect: [
            new Expectation\ReportsIncomplete(),
            new Expectation\Offers('calculateSum', 'calculateProduct'),
        ]),
    ],
);
