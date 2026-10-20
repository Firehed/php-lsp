<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$hover = fn (
    string $file,
    Marker\MarkerInterface $at,
    Expectation\HoverExpectationInterface $expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover($file, $at, expect: $expect),
    ],
);
$symbol = fn (string $marker): Marker\SymbolMarker => new Marker\SymbolMarker($marker);
$variable = fn (string $marker): Marker\VariableMarker => new Marker\VariableMarker($marker);
$user = 'src/Domain/User.php';

return [
    'built-in constant' => $hover($user, $symbol('builtin_constant'), new Expectation\Shows('PHP_VERSION')),
    'enum case' => $hover('src/Enum/Status.php', $symbol('enum_case'), new Expectation\Shows('case Active')),
    // `self` in a method an aliased trait import brings in is the using class.
    'self return through a trait alias' => $hover(
        'src/Hover/TraitAliasSelfReturnCall.php',
        $symbol('aliased_call'),
        new Expectation\Shows(': Fixtures\Hover\TraitAliasSelfReturnCall'),
    ),
    'method parameter declaration' => $hover(
        $user,
        $variable('set_name_param'),
        new Expectation\Shows('string $name'),
    ),
    'promoted parameter with a default' => $hover($user, $variable('promoted_age'), new Expectation\Shows('$age = 0')),
    'function parameter declaration' => $hover(
        'AutoloadFiles/helpers.php',
        $variable('helper_format_param'),
        new Expectation\Shows('string $value'),
    ),
    'closure parameter declaration' => $hover(
        'src/Resolution/ClosureParameter.php',
        $variable('closure_param'),
        new Expectation\Shows('string $captured'),
    ),
    'named argument' => $hover('SignatureHelp.php', $symbol('named_arg_b'), new Expectation\Shows('int $b')),
    'named argument to an attribute' => $hover(
        'src/Services/ApiController.php',
        $symbol('attr_named_arg'),
        new Expectation\Shows('string $path'),
    ),
];
