<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

// `$c ? null : new User()` is `?User` in PHP; the server takes only the first branch.
$user = 'src/Domain/User.php';

return [
    'a member of the object a ternary produces after null' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open($user),
            new Step\Definition(
                $user,
                new Marker\SymbolMarker('null_first_ternary'),
                expect: new Expectation\LandsOn($user, line: 48),
            ),
        ],
    ),
];
