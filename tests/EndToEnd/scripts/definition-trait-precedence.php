<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\SymbolMarker;

$file = 'src/Definition/TraitPrecedenceChild.php';

// A method from a used trait wins over the parent's method of the same name.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open('src/Definition/TraitPrecedenceParent.php'),
        new Open('src/Definition/TraitPrecedenceTrait.php'),
        new Open($file),
        new Ask(Feature::Definition, $file, new SymbolMarker('trait_precedence')),
    ],
);
