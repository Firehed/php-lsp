<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$hints = 'src/Completion/TypeHints.php';
$imports = 'Namespacing/ImportCompletion.php';

$common = [
    'string', 'int', 'float', 'bool', 'array', 'object',
    'mixed', 'iterable', 'callable', 'null', 'true', 'false',
];
$parameterTypes = [...$common, 'self', 'parent'];
$returnTypes = [...$common, 'void', 'never', 'self', 'static', 'parent'];

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

// Most type positions also offer every class-like in the project, so the list
// is capped and cannot show what is withheld; the unit tests cover that.
return [
    'return type prefix offers types, not functions' => $typeThenComplete(
        $hints,
        'return_type',
        'str',
        new Expectation\Offers('string'),
        new Expectation\Withholds('strlen', 'str_replace'),
    ),
    'parameter type prefix' => $typeThenComplete($hints, 'param_type', 'str', new Expectation\Offers('string')),
    'property type prefix' => $typeThenComplete($hints, 'property_type', 'str', new Expectation\Offers('string')),
    'return type' => $typeThenComplete($hints, 'return_type', '', new Expectation\Offers(...$returnTypes)),
    'return type after |' => $typeThenComplete($hints, 'return_type', 'int|', new Expectation\Offers(...$returnTypes)),
    'return type after &' => $typeThenComplete(
        $hints,
        'return_type',
        'Countable&',
        new Expectation\Offers(...$returnTypes),
    ),
    'return type after ?' => $typeThenComplete($hints, 'return_type', '?', new Expectation\Offers(...$returnTypes)),
    'return type after ? and a space' => $typeThenComplete(
        $hints,
        'return_type',
        '? ',
        new Expectation\Offers(...$returnTypes),
    ),
    'parameter type' => $typeThenComplete($hints, 'param_type', '', new Expectation\Offers(...$parameterTypes)),
    'property type after ?' => $typeThenComplete($hints, 'nullable_property', '', new Expectation\Offers(...$common)),
    'property type after |' => $typeThenComplete($hints, 'property_type', 'int|', new Expectation\Offers(...$common)),
    'property type after &' => $typeThenComplete(
        $hints,
        'property_type',
        'Countable&',
        new Expectation\Offers(...$common),
    ),
    'imported class in a type position' => $typeThenComplete(
        $imports,
        'type_hint_return',
        '',
        new Expectation\Offers('string', 'User'),
    ),
    'imported trait is not a type' => $typeThenComplete(
        $imports,
        'type_hint_return',
        'Sing',
        new Expectation\Withholds('SingletonTrait'),
    ),
    'imported trait is still offered in an expression' => $typeThenComplete(
        $imports,
        'trait_expression_partial',
        '',
        new Expectation\Offers('SingletonTrait'),
    ),
];
