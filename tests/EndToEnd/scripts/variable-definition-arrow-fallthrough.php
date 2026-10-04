<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\VariableMarker;

$file = 'src/Definition/VariableBindings.php';

// An arrow function captures its enclosing scope implicitly.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open($file),
        new Ask(Feature::Definition, $file, new VariableMarker('arrow_fallthrough')),
    ],
);
