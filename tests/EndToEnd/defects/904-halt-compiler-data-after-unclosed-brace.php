<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

// An unclosed brace puts __halt_compiler() in an inner scope while the code is
// being edited; once the brace is closed, everything after it is data.
$halt = 'Position/HaltCompiler.php';

return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($halt),
        new Step\Type($halt, new Marker\CursorMarker('before_halt'), 'function f() {'),
        new Step\Complete($halt, new Marker\CursorMarker('data_word'), expect: new Expectation\NoAnswer()),
    ],
);
