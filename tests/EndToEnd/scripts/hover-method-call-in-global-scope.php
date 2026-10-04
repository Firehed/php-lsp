<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\SymbolMarker;

$file = 'TopLevel/global_scope_hover.php';

return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open('src/Domain/User.php'),
        new Open($file),
        new Ask(Feature::Hover, $file, new SymbolMarker('global_method_call')),
    ],
);
