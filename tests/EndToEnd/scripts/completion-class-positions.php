<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$complete = fn (
    string $file,
    string $marker,
    Expectation\CompletionExpectationInterface ...$expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Complete($file, new Marker\CursorMarker($marker), expect: array_values($expect)),
    ],
);

$new = 'src/Completion/NewCompletion.php';
$newCursor = new Marker\CursorMarker('new_abstract');
$nonClassLikes = ['User', 'Entity', 'SingletonTrait', 'Status'];

return [
    'attribute' => $complete(
        'src/Completion/AttributeCompletion.php',
        'attr_empty',
        new Expectation\Offers('Route', 'NoConstructorAttribute'),
        new Expectation\Withholds(...$nonClassLikes),
    ),
    'attribute prefix' => $complete(
        'src/Completion/AttributePrefixCompletion.php',
        'attr_prefix',
        new Expectation\Offers('Route'),
        new Expectation\Withholds('round'),
    ),
    'attribute after a comma' => $complete(
        'src/Completion/AttributeGroupedCompletion.php',
        'attr_grouped',
        new Expectation\Offers('Route'),
        new Expectation\Withholds('round'),
    ),
    'implements' => $complete(
        'src/Completion/ImplementsCompletion.php',
        'implements_empty',
        new Expectation\Offers('Entity'),
        new Expectation\Withholds('User', 'SingletonTrait', 'Status'),
    ),
    'implements prefix offers the interface, not functions' => $complete(
        'src/Completion/ImplementsPrefixCompletion.php',
        'implements_d_prefix',
        new Expectation\Offers('Describable'),
        new Expectation\Withholds('date_add'),
    ),
    'implements a built-in interface' => $complete(
        'src/Completion/ImplementsBuiltinCompletion.php',
        'implements_builtin',
        new Expectation\Offers('SessionHandlerInterface'),
        new Expectation\Withholds('SessionHandler', 'session_start'),
    ),
    'interface extends' => $complete(
        'src/Completion/InterfaceExtendsCompletion.php',
        'interface_extends_empty',
        new Expectation\Offers('Entity'),
        new Expectation\Withholds('User', 'SingletonTrait', 'Status'),
    ),
    'interface extends prefix' => $complete(
        'src/Completion/InterfaceExtendsPrefixCompletion.php',
        'interface_extends_d_prefix',
        new Expectation\Offers('Describable'),
        new Expectation\Withholds('date_add'),
    ),
    'interface extends after a comma' => $complete(
        'src/Completion/InterfaceExtendsListCompletion.php',
        'interface_extends_list',
        new Expectation\Offers('Describable'),
        new Expectation\Withholds('date_add'),
    ),
    'class extends' => $complete(
        'src/Completion/ClassExtendsCompletion.php',
        'class_extends_empty',
        new Expectation\Offers('ParentClass'),
        new Expectation\Withholds('FinalDescendant', 'Entity', 'SingletonTrait', 'Status'),
    ),
    'class extends prefix' => $complete(
        'src/Completion/ClassExtendsPrefixCompletion.php',
        'class_extends_p_prefix',
        new Expectation\Offers('ParentClass'),
        new Expectation\Withholds('printf'),
    ),
    'catch' => $complete(
        'src/Completion/CatchCompletion.php',
        'catch_empty',
        new Expectation\Offers('AppException', 'ExceptionInterface'),
        new Expectation\Withholds(...$nonClassLikes),
    ),
    'catch after a pipe' => $complete(
        'src/Completion/MultiCatchCompletion.php',
        'catch_multi',
        new Expectation\Offers('ExceptionInterface'),
        new Expectation\Withholds('User', 'strlen'),
    ),
    'catch prefix' => $complete(
        'src/Completion/CatchPrefixCompletion.php',
        'catch_a_prefix',
        new Expectation\Offers('AppException'),
        new Expectation\Withholds('array_map'),
    ),
    'instanceof' => $complete(
        'src/Completion/InstanceofCompletion.php',
        'instanceof_empty',
        new Expectation\Offers('User', 'Entity', 'Status'),
        new Expectation\Withholds('SingletonTrait', 'string'),
    ),
    'new offers a class from the current namespace' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open($new),
            new Step\Type($new, $newCursor, 'AttributeC'),
            new Step\Complete($new, $newCursor, expect: [new Expectation\Offers('AttributeCompletion')]),
        ],
    ),
    // ClassModifiers.php declares several classes, so it is not autoloadable:
    // the server learns AbstractBase is abstract only once the file is open.
    'new withholds abstract classes and interfaces' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open('src/Utility/ClassModifiers.php'),
            new Step\Open($new),
            new Step\Complete($new, $newCursor, expect: [
                new Expectation\Offers('SealedClass'),
                new Expectation\Withholds('AbstractBase', 'Entity'),
            ]),
        ],
    ),
];
