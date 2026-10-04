<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\SymbolMarker;

$file = 'src/Services/ApiController.php';

return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open('src/Attributes/Route.php'),
        new Open($file),
        new Ask(Feature::Hover, $file, new SymbolMarker('attr_class')),
    ],
);
