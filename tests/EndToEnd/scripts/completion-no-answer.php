<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$complete = fn (
    string $file,
    string $marker,
    Expectation\CompletionExpectationInterface $expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Complete($file, new Marker\CursorMarker($marker), expect: $expect),
    ],
);
$filtering = 'src/Completion/ContextFiltering.php';
$functions = 'src/Completion/FunctionCompletion.php';
$finished = new Marker\CursorMarker('user_function');

return [
    'a comment' => $complete($filtering, 'in_comment', new Expectation\NoAnswer()),
    'member access in a comment' => $complete($filtering, 'member_in_comment', new Expectation\NoAnswer()),
    'a heredoc offers variables' => $complete($filtering, 'in_heredoc', new Expectation\Offers('$localVar')),
    'after a finished statement' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open($functions),
            new Step\Type($functions, $finished, '();'),
            new Step\Complete($functions, $finished, expect: new Expectation\NoAnswer()),
        ],
    ),
];
