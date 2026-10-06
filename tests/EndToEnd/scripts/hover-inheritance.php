<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$child = 'src/Inheritance/ChildClass.php';
$parent = 'src/Inheritance/ParentClass.php';

$hover = fn (
    string $file,
    string $marker,
    Expectation\HoverExpectationInterface ...$expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover($file, new Marker\SymbolMarker($marker), expect: array_values($expect)),
    ],
);

return [
    'inherited method' => $hover(
        $child,
        'inherited_method',
        new Expectation\Shows('parentMethod', 'Parent method documentation'),
    ),
    'inherited property' => $hover(
        $child,
        'inherited_property',
        new Expectation\Shows('$parentProperty', 'Parent property'),
    ),
    'grandparent method' => $hover(
        $child,
        'grandparent_method',
        new Expectation\Shows('grandparentMethod', 'Grandparent method documentation'),
    ),
    'grandparent property' => $hover(
        $child,
        'grandparent_property',
        new Expectation\Shows('$grandparentProperty', 'Grandparent property'),
    ),
    // A parent's private members are not visible from the child.
    'private inherited method' => $hover($child, 'private_method', new Expectation\NoAnswer()),
    'private inherited property' => $hover($child, 'private_property', new Expectation\NoAnswer()),
    // The child's override is shown, not the parent's version.
    'overridden method' => $hover(
        $child,
        'overridden_method',
        new Expectation\Shows('overriddenMethod', 'Child implementation'),
        new Expectation\Hides('Parent implementation'),
    ),
    'overridden property' => $hover(
        $child,
        'shared_property',
        new Expectation\Shows('$sharedProperty', 'Child override'),
        new Expectation\Hides('Shared property from parent'),
    ),
    'self static method' => $hover(
        $child,
        'self_method',
        new Expectation\Shows('staticMethod', 'Static method documentation'),
    ),
    'static static method' => $hover(
        $child,
        'static_method',
        new Expectation\Shows('staticMethod', 'Static method documentation'),
    ),
    'parent method' => $hover(
        $child,
        'parent_method',
        new Expectation\Shows('parentMethod', 'Parent method documentation'),
    ),
    'self static property' => $hover(
        $child,
        'self_property',
        new Expectation\Shows('$staticProperty', 'Static property documentation'),
    ),
    'static static property' => $hover(
        $child,
        'static_property',
        new Expectation\Shows('$staticProperty', 'Static property documentation'),
    ),
    'parent static property' => $hover(
        $child,
        'parent_property',
        new Expectation\Shows('$staticProperty', 'Static property documentation'),
    ),
    'static property' => $hover(
        $parent,
        'staticProperty',
        new Expectation\Shows('$staticProperty', 'static', 'Static property documentation'),
    ),
    'class constant' => $hover($parent, 'class_constant', new Expectation\Shows('PARENT_CONST')),
    'inherited across namespaces' => $hover(
        'Namespacing/CrossNamespaceInheritance.php',
        'cross_namespace_method',
        new Expectation\Shows('baseMethod', 'Method from Base namespace'),
    ),
    'interface method' => $hover(
        'src/Domain/Person.php',
        'interface_method',
        new Expectation\Shows('getName', "Gets the person's name"),
    ),
];
