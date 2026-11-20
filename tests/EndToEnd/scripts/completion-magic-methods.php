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

return [
    'a method inserts the signature PHP gives it' => $typeThenComplete(
        'src/Completion/Keywords.php',
        'class_body',
        'public function __to',
        new Expectation\Inserts('__toString', '__toString(): string'),
    ),
    'a static method is offered only static magic methods' => $typeThenComplete(
        'src/Completion/Keywords.php',
        'class_body',
        'public static function __',
        new Expectation\Offers('__callStatic', '__set_state'),
        new Expectation\Withholds('__call', '__construct'),
    ),
    'an enum is offered only the magic methods it may declare' => $typeThenComplete(
        'MultiClass/MagicMethods.php',
        'enum_body',
        'public function __',
        new Expectation\Offers('__call', '__invoke'),
        new Expectation\Withholds('__construct', '__toString'),
    ),
];
