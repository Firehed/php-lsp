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
$import = 'Namespacing/UseCompletion.php';

return [
    'import navigates from the global namespace' => $complete(
        $import,
        'use_first_segment',
        new Expectation\Offers('Psr\Http\\'),
    ),
    'import offers a workspace class by its leaf name' => $complete(
        $import,
        'use_workspace_class',
        new Expectation\Offers('User'),
    ),
    'trait use offers imported traits only' => $complete(
        'Namespacing/TraitUseCompletion.php',
        'trait_use',
        new Expectation\Offers('HasTimestamps'),
        new Expectation\Withholds('HasThing'),
    ),
    'trait use offers a same-namespace trait without an import' => $complete(
        'src/Traits/TraitUseFromSameNamespace.php',
        'same_ns_trait',
        new Expectation\Offers('SingletonTrait'),
    ),
    'closure use offers variables' => $complete(
        'Namespacing/ClosureUseCompletion.php',
        'closure_capture',
        new Expectation\Offers('$greeting'),
        new Expectation\Withholds('Psr\Http\\'),
    ),
];
