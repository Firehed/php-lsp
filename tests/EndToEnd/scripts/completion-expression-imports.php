<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$imports = 'Namespacing/ImportCompletion.php';
$models = 'Fixtures\Namespacing\Models';
$complete = fn (
    string $marker,
    Expectation\CompletionExpectationInterface ...$expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($imports),
        new Step\Complete($imports, new Marker\CursorMarker($marker), expect: array_values($expect)),
    ],
);

return [
    'imported class' => $complete('imported_class_partial', new Expectation\Details('User', "{$models}\User")),
    'aliased import' => $complete(
        'aliased_class_partial',
        new Expectation\Details('Repo', "{$models}\UserRepository"),
    ),
    'grouped import' => $complete('grouped_import_partial', new Expectation\Details('User', "{$models}\User")),
    'a class import and a function import of one short name' => $complete(
        'colliding_partial',
        new Expectation\Details('Widget', "{$models}\Widget"),
    ),
    'imported function' => $complete('imported_function_partial', new Expectation\Offers('makeUser')),
    'imported constant' => $complete('imported_constant_partial', new Expectation\Offers('DEFAULT_LIMIT')),
];
