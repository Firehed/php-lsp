<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\CursorMarker;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;

$file = 'SignatureHelp.php';

return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open($file),
        new Ask(Feature::SignatureHelp, $file, new CursorMarker('typed_param')),
    ],
);
