<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$noAnswer = fn (string $file, Marker\MarkerInterface $at): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Definition($file, $at, expect: new Expectation\NoAnswer()),
    ],
);

$parentless = 'EdgeCases/ParentWithoutExtends.php';
$outside = 'EdgeCases/SelfOutsideClass.php';
$unknown = 'EdgeCases/UnknownTypeMethod.php';
$builtin = 'EdgeCases/BuiltinDefinitions.php';

return [
    'parent method without extends' => $noAnswer($parentless, new Marker\SymbolMarker('parent_method')),
    'new parent without extends' => $noAnswer($parentless, new Marker\CursorMarker('def_new_parent')),
    'self method outside a class' => $noAnswer($outside, new Marker\SymbolMarker('self_method')),
    'new self outside a class' => $noAnswer($outside, new Marker\CursorMarker('def_new_self')),
    'method on an untyped parameter' => $noAnswer($unknown, new Marker\SymbolMarker('untyped_param')),
    'unknown method' => $noAnswer($unknown, new Marker\SymbolMarker('unknown_method')),
    // Built-ins come from reflection and have no file to go to.
    'built-in class' => $noAnswer($builtin, new Marker\SymbolMarker('builtin_class')),
    'built-in method' => $noAnswer($builtin, new Marker\SymbolMarker('builtin_method')),
];
