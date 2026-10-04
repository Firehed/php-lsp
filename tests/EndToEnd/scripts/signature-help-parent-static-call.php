<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\CursorMarker;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;

$file = 'src/Inheritance/ChildClass.php';

// `parent::` is the parent class, not the enclosing one (#101). ChildClass has
// no constructor, so `$name` can only come from ParentClass.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Open('src/Inheritance/Grandparent.php'),
        new Open('src/Inheritance/ParentClass.php'),
        new Open($file),
        new Ask(Feature::SignatureHelp, $file, new CursorMarker('parent_sig')),
    ],
);
