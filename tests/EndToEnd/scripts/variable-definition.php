<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$bindings = 'src/Definition/VariableBindings.php';
$global = 'TopLevel/global_scope_variable_jtd.php';
$topLevel = 'TopLevel/top_level_closures.php';

$definition = fn (
    string $file,
    string $marker,
    Expectation\DefinitionExpectationInterface $expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Definition($file, new Marker\VariableMarker($marker), expect: $expect),
    ],
);

return [
    'assignment' => $definition(
        $bindings,
        'assignment_usage',
        new Expectation\LandsOn($bindings, line: 11, column: 9),
    ),
    'parameter' => $definition(
        $bindings,
        'param_usage',
        new Expectation\LandsOn($bindings, line: 15, column: 41),
    ),
    'foreach value' => $definition(
        $bindings,
        'foreach_value_usage',
        new Expectation\LandsOn($bindings, line: 22, column: 28),
    ),
    'foreach key' => $definition(
        $bindings,
        'foreach_key_usage',
        new Expectation\LandsOn($bindings, line: 29, column: 32),
    ),
    'catch' => $definition(
        $bindings,
        'catch_usage',
        new Expectation\LandsOn($bindings, line: 38, column: 29),
    ),
    // Of two assignments, the nearest one before the usage is the definition.
    'step back to earlier assignment' => $definition(
        $bindings,
        'second_x',
        new Expectation\LandsOn($bindings, line: 46),
    ),
    'parameter shadows outer' => $definition(
        $bindings,
        'shadowed_usage',
        new Expectation\LandsOn($bindings, line: 50),
    ),
    'use clause' => $definition(
        $bindings,
        'use_clause_usage',
        new Expectation\LandsOn($bindings, line: 58, column: 32),
    ),
    // An arrow function captures its enclosing scope implicitly.
    'arrow function falls through' => $definition(
        $bindings,
        'arrow_fallthrough',
        new Expectation\LandsOn($bindings, line: 73),
    ),
    // A long closure sees only what its `use` clause captures, so an outer
    // variable of the same name is not its definition.
    'closure leaves outer name uncaptured' => $definition(
        $bindings,
        'closure_uncaptured',
        new Expectation\NoAnswer(),
    ),
    // `$this` is implicit, so it has no definition to go to.
    'this' => $definition($bindings, 'this_usage', new Expectation\NoAnswer()),
    'global scope' => $definition(
        $global,
        'global_assignment_usage',
        new Expectation\LandsOn($global, line: 7),
    ),
    // A top-level arrow function has no enclosing function to capture from, so
    // the file-scope assignment is not its definition.
    'top-level arrow capture' => $definition(
        $topLevel,
        'top_arrow_capture',
        new Expectation\NoAnswer(),
    ),
    // A top-level closure's `use` clause binds the name, so it is the definition.
    'top-level closure use' => $definition(
        $topLevel,
        'top_closure_use_capture',
        new Expectation\LandsOn($topLevel, line: 15, column: 29),
    ),
];
