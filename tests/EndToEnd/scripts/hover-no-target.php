<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$noAnswer = fn (string $file, Marker\MarkerInterface $at): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover($file, $at, expect: new Expectation\NoAnswer()),
    ],
);

$outside = 'EdgeCases/SelfOutsideClass.php';
$parentless = 'EdgeCases/ParentWithoutExtends.php';

return [
    'self method outside a class' => $noAnswer($outside, new Marker\SymbolMarker('self_method')),
    'self property outside a class' => $noAnswer($outside, new Marker\SymbolMarker('self_property')),
    'parent method without extends' => $noAnswer($parentless, new Marker\SymbolMarker('parent_method')),
    'parent property without extends' => $noAnswer($parentless, new Marker\SymbolMarker('parent_property')),
    'inside a comment' => $noAnswer('EdgeCases/HoverInComment.php', new Marker\CursorMarker('in_comment')),
];
