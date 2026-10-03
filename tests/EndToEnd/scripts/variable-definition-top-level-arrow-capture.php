<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\VariableMarker;

$file = 'TopLevel/top_level_closures.php';

// A top-level arrow function has no enclosing function to capture from, so
// the file-scope assignment is not its definition.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open($file),
        new Ask(Feature::Definition, $file, new VariableMarker('top_arrow_capture')),
    ],
);
