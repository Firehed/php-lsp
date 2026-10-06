<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$noAnswer = fn (string $file, string $marker): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\SignatureHelp($file, new Marker\CursorMarker($marker), expect: new Expectation\NoAnswer()),
    ],
);

$outside = 'EdgeCases/SelfOutsideClass.php';
$parentless = 'EdgeCases/ParentWithoutExtends.php';

return [
    'self method outside a class' => $noAnswer($outside, 'sig_self_method'),
    'new self outside a class' => $noAnswer($outside, 'sig_new_self'),
    'parent method without extends' => $noAnswer($parentless, 'sig_parent_method'),
    'new parent without extends' => $noAnswer($parentless, 'sig_new_parent'),
];
