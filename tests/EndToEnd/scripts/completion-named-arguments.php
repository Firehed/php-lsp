<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$named = 'src/Completion/NamedArguments.php';
$editing = 'src/Completion/EditingNamedArg.php';
$attribute = 'src/Completion/AttributeNamedArguments.php';

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

// Most positions here also offer every function and constant, so the list is
// capped and cannot show that an argument already supplied is withheld; the
// unit tests cover that.
return [
    'every parameter, with its signature' => $complete(
        $named,
        'named_empty',
        new Expectation\Offers('name:', 'count:', 'active:'),
        new Expectation\Details('name:', 'string $name'),
    ),
    'static call' => $complete($named, 'static_named_empty', new Expectation\Offers('value:', 'limit:')),
    'constructor' => $complete($named, 'new_named_empty', new Expectation\Offers('name:', 'age:')),
    'function call' => $complete($named, 'procedural_empty', new Expectation\Offers('message:', 'level:', 'verbose:')),
    'attribute' => $complete($attribute, 'attr_arg_empty', new Expectation\Offers('path:', 'method:')),
    'attribute after a positional argument' => $complete(
        $attribute,
        'attr_arg_second',
        new Expectation\Offers('method:'),
    ),
    'after a positional argument' => $complete($named, 'after_positional', new Expectation\Offers('count:', 'active:')),
    'nullsafe call' => $complete($named, 'nullsafe_method_call', new Expectation\Offers('count:', 'active:')),
    'before a named argument' => $complete($named, 'after_named', new Expectation\Offers('active:')),
    'between two named arguments' => $complete($named, 'middle_named', new Expectation\Offers('count:')),
    'between positional and named arguments' => $complete(
        $named,
        'mixed_positional_named',
        new Expectation\Offers('active:'),
    ),
    'function call before a named argument' => $complete(
        $named,
        'procedural_after_named',
        new Expectation\Offers('verbose:'),
    ),
    'variadic call' => $complete($named, 'variadic_excluded', new Expectation\Offers('name:')),
    'prefix' => $complete(
        $named,
        'incomplete_with_prefix',
        new Expectation\Offers('name:'),
        new Expectation\Withholds('count:', 'active:'),
    ),
    'alongside variables' => $complete($named, 'additive_with_variable', new Expectation\Offers('name:', '$localVar')),
    // ParamClass shares a file with NamedArguments, outside the autoload
    // layout, so the server knows it only while that file is open.
    'before an orphaned colon' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open($named),
            new Step\Open($editing),
            new Step\Complete(
                $editing,
                new Marker\CursorMarker('editing_before_colon'),
                expect: new Expectation\Offers('age:'),
            ),
        ],
    ),
    'right after a numeric value, which is still the value' => $complete(
        $editing,
        'after_named_value',
        new Expectation\Withholds('count:'),
    ),
    'variable prefix offers variables and names, not expressions' => $complete(
        $editing,
        'variable_in_call',
        new Expectation\Offers('$variable', 'name:'),
        new Expectation\Withholds('new'),
    ),
    'value position offers expression keywords' => $complete(
        $named,
        'after_colon',
        new Expectation\Offers('new', 'true', 'false', 'null'),
    ),
    'value position with a prefix' => $complete(
        $named,
        'after_colon_prefix',
        new Expectation\Offers('new', 'null'),
        new Expectation\Withholds('true', 'if', 'name:'),
    ),
    // A prefix no function shares keeps the list short enough to be complete,
    // so it can show the remaining argument names are withheld.
    'value position offers no argument names' => $complete(
        $named,
        'after_colon_unmatched_prefix',
        new Expectation\Withholds('count:', 'active:'),
    ),
    'argument position offers names before expression keywords' => $complete(
        $named,
        'expression_in_bare_arg',
        new Expectation\Offers('name:', 'new', 'null'),
        new Expectation\Withholds('if', 'class'),
        new Expectation\RanksAbove('name:', 'new'),
    ),
    'argument position offers functions' => $complete(
        $named,
        'expression_in_second_arg',
        new Expectation\Offers('array_map'),
    ),
];
