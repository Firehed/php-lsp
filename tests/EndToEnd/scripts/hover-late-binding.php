<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/LateBinding/CallSites.php';

$hover = fn (string $marker, string $returnType): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover($file, new Marker\SymbolMarker($marker), expect: new Expectation\Shows(": {$returnType}")),
    ],
);

// Every receiver is a Sub: `static` follows the receiver, while `self` and
// `parent` follow the class that declares the method.
return [
    'static return on a method call' => $hover('method_call_static', 'Fixtures\\LateBinding\\Sub'),
    'static return on a nullsafe method call' => $hover('nullsafe_method_call_static', 'Fixtures\\LateBinding\\Sub'),
    'static return on a static call' => $hover('static_call_static', 'Fixtures\\LateBinding\\Sub'),
    'self return' => $hover('method_call_self', 'Fixtures\\LateBinding\\Base'),
    'parent return' => $hover('method_call_parent', 'Fixtures\\LateBinding\\Base'),
    // A trait's `self` names the class using it.
    'self property from a trait' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open($file),
            new Step\Hover(
                $file,
                new Marker\SymbolMarker('trait_self_property'),
                expect: new Expectation\Shows('?Fixtures\\LateBinding\\Node $next'),
            ),
        ],
    ),
    // `parent::` passes on the calling class, so `static` is Sub, not Base.
    'static return on a parent call' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open('src/LateBinding/Sub.php'),
            new Step\Hover(
                'src/LateBinding/Sub.php',
                new Marker\SymbolMarker('parent_call_static'),
                expect: new Expectation\Shows(': Fixtures\\LateBinding\\Sub'),
            ),
        ],
    ),
    // Completion reads the same bound type as hover.
    'static return in completion' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open($file),
            new Step\Complete(
                $file,
                new Marker\CursorMarker('completion_static_return'),
                expect: [
                    new Expectation\Details(
                        'copyInstance',
                        'public function copyInstance(): Fixtures\\LateBinding\\Sub',
                    ),
                ],
            ),
        ],
    ),
];
