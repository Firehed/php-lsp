<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

// PHP assigns after evaluating the right side, so the variable being assigned
// is not yet bound while its right side is typed.
$typeRightSide = fn (string $file, string $marker): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Type($file, new Marker\CursorMarker($marker), '$fresh = $'),
        new Step\Complete($file, new Marker\CursorMarker($marker), expect: new Expectation\Withholds('$fresh')),
    ],
);

return [
    'before the end of a block' => $typeRightSide('src/Completion/EdgeCases.php', 'not_member_access'),
    'at the end of the file' => $typeRightSide('TopLevel/global_scope_nested.php', 'nested_marker'),
];
