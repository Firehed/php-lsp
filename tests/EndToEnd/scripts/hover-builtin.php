<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$usage = 'src/Hover/BuiltinUsage.php';
$types = 'src/TypeInference/BuiltinTypes.php';

$hover = fn (
    string $file,
    string $marker,
    Expectation\HoverExpectationInterface $expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover($file, new Marker\SymbolMarker($marker), expect: $expect),
    ],
);

return [
    'function' => $hover($usage, 'builtin_function', new Expectation\Shows('function sort(')),
    'class method' => $hover($usage, 'builtin_class_method', new Expectation\Shows('getArrayCopy')),
    'class property' => $hover($types, 'builtin_class_property', new Expectation\Shows('$message')),
    // A clone has the type of what it copies, so its method resolves.
    'clone receiver' => $hover($types, 'clone_receiver', new Expectation\Shows('getTimestamp')),
    // A coalesce takes a type from its operands, so its method resolves.
    'null coalesce receiver' => $hover($types, 'coalesce_receiver', new Expectation\Shows('getTimestamp')),
];
