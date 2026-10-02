<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\CursorMarker;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\Type;

$file = 'src/Completion/MethodAccess.php';
$cursor = new CursorMarker('this_empty');

return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open($file),
        new Ask(Feature::Completion, $file, $cursor),
        new Type($file, $cursor, 'get'),
        new Ask(Feature::Completion, $file, $cursor),
    ],
);
