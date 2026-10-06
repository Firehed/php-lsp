<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'EdgeCases/DynamicAccess.php';

$definition = fn (
    string $marker,
    Expectation\DefinitionExpectationInterface $expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Definition($file, new Marker\SymbolMarker($marker), expect: $expect),
    ],
);

return [
    'class name' => $definition('dynamic_class_name', new Expectation\NoAnswer()),
    'static property on a variable class' => $definition('dynamic_class_static_prop', new Expectation\NoAnswer()),
    'constant on a variable class' => $definition('dynamic_class_const', new Expectation\NoAnswer()),
    // In `$this->$method()` and `self::$method()` the cursor is on the
    // variable, so its assignment is the definition.
    'instance method name' => $definition('dynamic_instance_method', new Expectation\LandsOn($file, line: 16)),
    'static method name' => $definition('dynamic_static_method', new Expectation\LandsOn($file, line: 10)),
];
