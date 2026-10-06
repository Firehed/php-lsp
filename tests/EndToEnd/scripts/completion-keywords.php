<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$keywords = 'src/Completion/Keywords.php';

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
    'loop' => $typeThenComplete($keywords, 'statement', 'fore', new Expectation\Offers('foreach')),
    'control flow' => $typeThenComplete($keywords, 'statement', 'ret', new Expectation\Offers('return')),
    'declaration' => $typeThenComplete($keywords, 'statement', 'cla', new Expectation\Offers('class')),
    'class body offers only class-level keywords' => $typeThenComplete(
        $keywords,
        'class_body',
        'p',
        new Expectation\Offers('public', 'private', 'protected'),
        new Expectation\Withholds('print_r', 'print'),
    ),
    'after visibility offers function' => $typeThenComplete(
        $keywords,
        'after_visibility',
        'f',
        new Expectation\Offers('function'),
    ),
    'after visibility offers modifiers and types' => $typeThenComplete(
        $keywords,
        'after_visibility',
        's',
        new Expectation\Offers('static', 'string'),
    ),
    'no statement keywords in a return type' => $typeThenComplete(
        'src/Completion/TypeHints.php',
        'return_type',
        'ret',
        new Expectation\Withholds('return'),
    ),
];
