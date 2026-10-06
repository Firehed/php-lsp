<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$child = 'src/Inheritance/ChildClass.php';
$parent = 'src/Inheritance/ParentClass.php';

$definition = fn (
    string $file,
    string $marker,
    Expectation\DefinitionExpectationInterface $expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Definition($file, new Marker\SymbolMarker($marker), expect: $expect),
    ],
);

return [
    'inherited method' => $definition($child, 'inherited_method', new Expectation\LandsOn($parent, line: 29)),
    'overridden method' => $definition($child, 'overridden_method', new Expectation\LandsOn($child, line: 22)),
    'parent method' => $definition($child, 'parent_method', new Expectation\LandsOn($parent, line: 29)),
    'self method' => $definition($child, 'self_method', new Expectation\LandsOn($parent, line: 39)),
    'static keyword method' => $definition($child, 'static_method', new Expectation\LandsOn($parent, line: 39)),
    'private method' => $definition($parent, 'private_method_internal', new Expectation\LandsOn($parent, line: 59)),
    'protected method' => $definition($parent, 'protected_method_internal', new Expectation\LandsOn($parent, line: 54)),
    'class constant' => $definition($parent, 'class_constant', new Expectation\LandsOn($parent, line: 9)),
    // A method from a used trait wins over the parent's method of the same name.
    'trait precedence' => $definition(
        'src/Definition/TraitPrecedenceChild.php',
        'trait_precedence',
        new Expectation\LandsOn('src/Definition/TraitPrecedenceTrait.php', line: 9),
    ),
];
