<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\VariableMarker;

$file = 'src/Definition/VariableBindings.php';

// A long closure sees only what its `use` clause captures, so an outer
// variable of the same name is not its definition.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open($file),
        new Ask(Feature::Definition, $file, new VariableMarker('closure_uncaptured')),
    ],
);
