<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\CursorMarker;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;

$file = 'src/Completion/AttributeNamedArguments.php';

return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open('src/Attributes/Route.php'),
        new Open($file),
        new Ask(Feature::SignatureHelp, $file, new CursorMarker('attr_arg_empty')),
    ],
);
