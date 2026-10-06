<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/Completion/InheritanceCompletion.php';

// Members come from the class itself, every ancestor, and used traits.
return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Complete($file, new Marker\CursorMarker('this_inherited'), expect: new Expectation\Offers(
            'ownProperty',
            'ownMethod',
            'childMethod',
            'parentMethod',
            'grandparentMethod',
            'createdAt',
            'updatedAt',
            'getCreatedAt',
            'markCreated',
        )),
    ],
);
