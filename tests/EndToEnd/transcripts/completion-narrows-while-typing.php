<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/Completion/MethodAccess.php';
$cursor = new Marker\CursorMarker('this_empty');

// Locks the open, change, and ask exchange as an editor sends it.
return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Complete($file, $cursor, expect: new Expectation\Offers('getName', 'setName', 'getCount', 'isActive')),
        new Step\Type($file, $cursor, 'get'),
        new Step\Complete($file, $cursor, expect: [
            new Expectation\Offers('getName', 'getCount'),
            new Expectation\Withholds('setName', 'isActive'),
        ]),
    ],
);
