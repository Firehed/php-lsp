<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\VariableMarker;

$file = 'TopLevel/top_level_closures.php';

// A top-level closure's `use` clause binds the name, so it is the definition.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open($file),
        new Ask(Feature::Definition, $file, new VariableMarker('top_closure_use_capture')),
    ],
);
