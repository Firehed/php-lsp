<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$variables = 'src/Completion/Variables.php';

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

return [
    'parameter with its type' => $complete(
        $variables,
        'param_prefix',
        new Expectation\Details('$name', 'string'),
        new Expectation\Withholds('$age'),
    ),
    'local variable' => $complete($variables, 'local_prefix', new Expectation\Offers('$logger')),
    '$this with its class' => $complete(
        $variables,
        'this_prefix',
        new Expectation\Details('$this', 'Fixtures\\Completion\\Variables'),
    ),
    'closure local' => $complete($variables, 'closure_local', new Expectation\Offers('$localVar')),
    'foreach value' => $complete($variables, 'foreach_prefix', new Expectation\Offers('$item')),
    'closures do not share scope' => $complete(
        'src/Completion/ClosureVariables.php',
        'closure_scope_isolated',
        new Expectation\Offers('$siteDir'),
        new Expectation\Withholds('$logger'),
    ),
    'file-level variable' => $complete(
        'TopLevel/global_scope_variable.php',
        'global_var_prefix',
        new Expectation\Offers('$currentUser'),
        new Expectation\Withholds('$loginCount'),
    ),
    // A multibyte character earlier on the line must not shorten the typed prefix.
    'prefix after a multibyte character' => $complete(
        'src/Completion/MultibyteCompletion.php',
        'var_after_emoji',
        new Expectation\Offers('$taxRate'),
        new Expectation\Withholds('$total'),
    ),
];
