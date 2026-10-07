<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$complete = fn (
    string $file,
    string $marker,
    Expectation\CompletionExpectationInterface $expect,
    bool $snippets = false,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Complete($file, new Marker\CursorMarker($marker), expect: $expect),
    ],
    capabilities: new Session\ClientCapabilities(snippetCompletion: $snippets),
);
$functions = 'src/Completion/FunctionCompletion.php';
$global = 'FunctionCompletion.php';
$members = 'src/Completion/MethodAccess.php';
$snippet = Result\InsertTextFormat::Snippet;

return [
    'built-in functions' => $complete(
        $functions,
        'builtin_function',
        new Expectation\Offers('array_map', 'array_filter'),
    ),
    'a user function shows its signature' => $complete(
        $global,
        'user_defined_function',
        new Expectation\Details('calculateSum', 'function calculateSum(int $a, int $b): int'),
    ),
    'a user function shows its documentation' => $complete(
        $global,
        'user_defined_function',
        new Expectation\Documents('calculateSum', 'Adds two numbers.'),
    ),
    'a conditionally declared function' => $complete(
        $global,
        'user_defined_function',
        new Expectation\Offers('calculateProduct'),
    ),
    'a method inserts a call snippet' => $complete(
        $members,
        'this_empty',
        new Expectation\Inserts('setName', 'setName($0)', $snippet),
        snippets: true,
    ),
    'a user function inserts a call snippet' => $complete(
        $functions,
        'user_function',
        new Expectation\Inserts('calculateSum', 'calculateSum($0)', $snippet),
        snippets: true,
    ),
    'a built-in function inserts a call snippet' => $complete(
        $functions,
        'builtin_function',
        new Expectation\Inserts('array_map', 'array_map($0)', $snippet),
        snippets: true,
    ),
    'a property inserts no call snippet' => $complete(
        $members,
        'this_empty',
        new Expectation\Inserts('name', 'name'),
        snippets: true,
    ),
    'without snippet support, a method inserts its name' => $complete(
        $members,
        'this_empty',
        new Expectation\Inserts('setName', 'setName'),
    ),
    'without snippet support, a function inserts its name' => $complete(
        $functions,
        'user_function',
        new Expectation\Inserts('calculateSum', 'calculateSum'),
    ),
];
