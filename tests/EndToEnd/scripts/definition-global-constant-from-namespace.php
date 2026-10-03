<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\SymbolMarker;

$file = 'src/Resolution/NamespacedConstant.php';

// An unqualified constant not defined in the namespace falls back to the
// global one.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open('AutoloadFiles/helpers.php'),
        new Open($file),
        new Ask(Feature::Definition, $file, new SymbolMarker('global_const_fetch')),
    ],
);
