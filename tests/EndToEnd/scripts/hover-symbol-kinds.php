<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$hover = fn (
    string $file,
    string $marker,
    Expectation\HoverExpectationInterface $expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover($file, new Marker\SymbolMarker($marker), expect: $expect),
    ],
);

return [
    'built-in constant' => $hover('src/Domain/User.php', 'builtin_constant', new Expectation\Shows('PHP_VERSION')),
    'enum case' => $hover('src/Enum/Status.php', 'enum_case', new Expectation\Shows('case Active')),
    // `self` in a method an aliased trait import brings in is the using class.
    'self return through a trait alias' => $hover(
        'src/Hover/TraitAliasSelfReturnCall.php',
        'aliased_call',
        new Expectation\Shows(': Fixtures\Hover\TraitAliasSelfReturnCall'),
    ),
];
