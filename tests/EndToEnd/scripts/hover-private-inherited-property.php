<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\SymbolMarker;

$file = 'src/Inheritance/ChildClass.php';

// A parent's private property is not visible from the child.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open('src/Inheritance/Grandparent.php'),
        new Open('src/Inheritance/ParentClass.php'),
        new Open($file),
        new Ask(Feature::Hover, $file, new SymbolMarker('private_property')),
    ],
);
