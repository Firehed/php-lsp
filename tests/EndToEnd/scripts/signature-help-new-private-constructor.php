<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\CursorMarker;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;

$file = 'src/LateBinding/PrivateCtorCaller.php';

// A private constructor is still shown, so its parameters can be read.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open('src/LateBinding/PrivateCtor.php'),
        new Open($file),
        new Ask(Feature::SignatureHelp, $file, new CursorMarker('sig_new_private_ctor')),
    ],
);
