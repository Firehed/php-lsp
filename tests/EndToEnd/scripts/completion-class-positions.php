<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$typeThenComplete = fn (
    string $file,
    string $marker,
    string $typed,
    Expectation\CompletionExpectationInterface ...$expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Type($file, new Marker\CursorMarker($marker), $typed),
        new Step\Complete($file, new Marker\CursorMarker($marker), expect: array_values($expect)),
    ],
);

$new = 'src/Completion/NewCompletion.php';
$nonClassLikes = ['User', 'Entity', 'SingletonTrait', 'Status'];

return [
    'attribute' => $typeThenComplete(
        'src/Completion/AttributeCompletion.php',
        'attr_empty',
        '',
        new Expectation\Offers('Route', 'NoConstructorAttribute'),
        new Expectation\Withholds(...$nonClassLikes),
    ),
    'attribute prefix' => $typeThenComplete(
        'src/Completion/AttributePrefixCompletion.php',
        'attr_prefix',
        '',
        new Expectation\Offers('Route'),
    ),
    'attribute after a comma' => $typeThenComplete(
        'src/Completion/AttributeGroupedCompletion.php',
        'attr_grouped',
        '',
        new Expectation\Offers('Route'),
    ),
    'implements' => $typeThenComplete(
        'src/Completion/ImplementsCompletion.php',
        'implements_empty',
        '',
        new Expectation\Offers('Entity'),
        new Expectation\Withholds('User', 'SingletonTrait', 'Status'),
    ),
    'implements prefix offers the interface, not functions' => $typeThenComplete(
        'src/Completion/ImplementsPrefixCompletion.php',
        'implements_d_prefix',
        '',
        new Expectation\Offers('Describable'),
        new Expectation\Withholds('date_add'),
    ),
    'implements a built-in interface' => $typeThenComplete(
        'src/Completion/ImplementsBuiltinCompletion.php',
        'implements_builtin',
        '',
        new Expectation\Offers('SessionHandlerInterface'),
        new Expectation\Withholds('SessionHandler', 'session_start'),
    ),
    'interface extends' => $typeThenComplete(
        'src/Completion/InterfaceExtendsCompletion.php',
        'interface_extends_empty',
        '',
        new Expectation\Offers('Entity'),
        new Expectation\Withholds('User', 'SingletonTrait', 'Status'),
    ),
    'interface extends prefix' => $typeThenComplete(
        'src/Completion/InterfaceExtendsPrefixCompletion.php',
        'interface_extends_d_prefix',
        '',
        new Expectation\Offers('Describable'),
        new Expectation\Withholds('date_add'),
    ),
    'interface extends after a comma' => $typeThenComplete(
        'src/Completion/InterfaceExtendsListCompletion.php',
        'interface_extends_list',
        '',
        new Expectation\Offers('Describable'),
    ),
    'class extends' => $typeThenComplete(
        'src/Completion/ClassExtendsCompletion.php',
        'class_extends_empty',
        '',
        new Expectation\Offers('ParentClass'),
        new Expectation\Withholds('FinalDescendant', 'Entity', 'SingletonTrait', 'Status'),
    ),
    'class extends prefix' => $typeThenComplete(
        'src/Completion/ClassExtendsPrefixCompletion.php',
        'class_extends_p_prefix',
        '',
        new Expectation\Offers('ParentClass'),
        new Expectation\Withholds('printf'),
    ),
    'catch' => $typeThenComplete(
        'src/Completion/CatchCompletion.php',
        'catch_empty',
        '',
        new Expectation\Offers('AppException', 'ExceptionInterface'),
        new Expectation\Withholds(...$nonClassLikes),
    ),
    'catch after a pipe' => $typeThenComplete(
        'src/Completion/MultiCatchCompletion.php',
        'catch_multi',
        '',
        new Expectation\Offers('ExceptionInterface'),
        new Expectation\Withholds('User'),
    ),
    'catch prefix' => $typeThenComplete(
        'src/Completion/CatchPrefixCompletion.php',
        'catch_a_prefix',
        '',
        new Expectation\Offers('AppException'),
        new Expectation\Withholds('array_map'),
    ),
    'instanceof' => $typeThenComplete(
        'src/Completion/InstanceofCompletion.php',
        'instanceof_empty',
        '',
        new Expectation\Offers('User', 'Entity', 'Status'),
        new Expectation\Withholds('SingletonTrait', 'string'),
    ),
    'new offers a class from the current namespace' => $typeThenComplete(
        $new,
        'new_abstract',
        'AttributeC',
        new Expectation\Offers('AttributeCompletion'),
    ),
    // ClassModifiers.php declares several classes, so it is not autoloadable:
    // the server learns AbstractBase is abstract only once the file is open.
    'new withholds abstract classes and interfaces' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open('src/Utility/ClassModifiers.php'),
            new Step\Open($new),
            new Step\Complete($new, new Marker\CursorMarker('new_abstract'), expect: [
                new Expectation\Offers('SealedClass'),
                new Expectation\Withholds('AbstractBase', 'Entity'),
            ]),
        ],
    ),
];
