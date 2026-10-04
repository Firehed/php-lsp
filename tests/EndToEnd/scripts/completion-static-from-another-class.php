<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\CursorMarker;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;

$file = 'src/Completion/StaticCaller.php';

// From an unrelated class, only public static members are offered.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open($file),
        new Ask(Feature::Completion, $file, new CursorMarker('external_static')),
    ],
);
