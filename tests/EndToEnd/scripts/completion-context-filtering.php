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
$keywords = 'src/Completion/Keywords.php';
$commentLineEnd = fn (string $comment): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($keywords),
        new Step\Type($keywords, new Marker\CursorMarker('statement'), "{$comment}\n"),
        new Step\Complete($keywords, new Marker\LineEndAboveMarker('statement'), expect: new Expectation\NoAnswer()),
    ],
);

return [
    'a comment' => $complete($filtering, 'in_comment', new Expectation\NoAnswer()),
    'member access in a comment' => $complete($filtering, 'member_in_comment', new Expectation\NoAnswer()),
    'the end of a comment line' => $commentLineEnd('// arra'),
    'the end of a comment line with words before' => $commentLineEnd('// then call arra'),
    'the end of a hash comment line' => $commentLineEnd('# arra'),
    'the code before a trailing comment' => $complete(
        $filtering,
        'before_trailing_comment',
        new Expectation\Offers('array_map'),
    ),
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
