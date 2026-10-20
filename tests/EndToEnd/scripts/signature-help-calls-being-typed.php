<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$signatureHelp = fn (
    string $file,
    string $marker,
    Expectation\SignatureHelpExpectationInterface ...$expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\SignatureHelp($file, new Marker\CursorMarker($marker), expect: array_values($expect)),
    ],
);

$editing = 'src/Completion/EditingNamedArg.php';
$edgeCases = 'src/Completion/CoverageEdgeCases.php';
$staticWithParams = new Expectation\SignatureShows('staticWithParams');
$localHelper = new Expectation\SignatureShows('localHelper');
$userConstructor = new Expectation\SignatureShows('__construct', 'string $id');

return [
    'an imported function' => $signatureHelp(
        'src/Hover/BuiltinUsage.php',
        'imported_function_sig',
        new Expectation\SignatureShows('helperFormat'),
    ),
    'an unclosed attribute' => $signatureHelp(
        'src/Completion/AttributeNamedArgumentsIncomplete.php',
        'attr_arg_incomplete',
        new Expectation\SignatureShows('string $path'),
    ),
    'a method while its argument name is typed' => $signatureHelp(
        'src/Completion/NamedArguments.php',
        'incomplete_with_prefix',
        new Expectation\SignatureShows('multipleParams'),
    ),
    'a static call before a named argument' => $signatureHelp($editing, 'static_in_complete', $staticWithParams),
    'a static call before an orphaned colon' => $signatureHelp($editing, 'static_before_colon', $staticWithParams),
    'an unclosed static call' => $signatureHelp($editing, 'static_empty', $staticWithParams),
    'an unclosed method call' => $signatureHelp(
        $editing,
        'instance_empty',
        new Expectation\SignatureShows('instanceMethod'),
    ),
    'an unclosed nullsafe method call' => $signatureHelp(
        $editing,
        'nullsafe_empty',
        new Expectation\SignatureShows('instanceMethod'),
    ),
    'an unclosed function call' => $signatureHelp($editing, 'function_empty', $localHelper),
    'after an argument that holds its own call' => $signatureHelp(
        $editing,
        'nested_brackets',
        $localHelper,
        new Expectation\ActiveParameter(1),
    ),
    'right after a named argument\'s string value' => $signatureHelp($editing, 'after_string_value', $localHelper),
    'after a named argument' => $signatureHelp(
        $edgeCases,
        'after_named_arg',
        new Expectation\SignatureShows('methodWithNamed'),
    ),
    'new on a fully qualified class' => $signatureHelp($edgeCases, 'fqn_new', $userConstructor),
    'new on an imported class' => $signatureHelp(
        'src/Completion/UseStatementCall.php',
        'imported_new',
        $userConstructor,
    ),
    'new on a class imported outside any namespace' => $signatureHelp(
        'NoNamespace/TopLevelUse.php',
        'top_level_use',
        $userConstructor,
    ),
    // Widget is imported both as a class and as a function. Its file sits
    // outside the autoload layout, so it is known only while open.
    'new on a name imported as both a class and a function' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open('Namespacing/MultiNamespaceImports.php'),
            new Step\Open('src/IncompleteCode/AliasedImports.php'),
            new Step\SignatureHelp(
                'src/IncompleteCode/AliasedImports.php',
                new Marker\CursorMarker('colliding_new'),
                expect: new Expectation\SignatureShows('string $size'),
            ),
        ],
    ),
    '$this in a class without a namespace' => $signatureHelp(
        'NoNamespace.php',
        'this_call_no_ns',
        new Expectation\SignatureShows('staticMethod'),
    ),
];
