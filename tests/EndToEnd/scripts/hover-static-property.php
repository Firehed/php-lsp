<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\SymbolMarker;

$file = 'src/Inheritance/ParentClass.php';

return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open('src/Inheritance/Grandparent.php'),
        new Open($file),
        new Ask(Feature::Hover, $file, new SymbolMarker('staticProperty')),
    ],
);
