<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/Hover/ForeachElement.php';

$hover = fn (string $marker, Expectation\HoverExpectationInterface $expect): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover($file, new Marker\SymbolMarker($marker), expect: $expect),
    ],
);

// A foreach variable takes the element type from the `@return User[]`
// docblock of what it iterates (#301), whatever kind of expression that is.
$resolvesUser = new Expectation\Shows('getName');

return [
    'method call' => $hover('foreach_member', $resolvesUser),
    'nullsafe method call' => $hover('foreach_nullsafe_method_call', $resolvesUser),
    'static call' => $hover('foreach_static_call', $resolvesUser),
    'function call' => $hover('foreach_func_call', $resolvesUser),
    'function call, fully qualified docblock type' => $hover('foreach_func_call_fqn', $resolvesUser),
    'property fetch' => $hover('foreach_property_fetch', $resolvesUser),
    'nullsafe property fetch' => $hover('foreach_nullsafe_property_fetch', $resolvesUser),
    'static property fetch' => $hover('foreach_static_property_fetch', $resolvesUser),
    'class constant' => $hover('foreach_class_const_fetch', $resolvesUser),
    'constant' => $hover('foreach_const_fetch', $resolvesUser),
    // A docblock without an array element type gives the element no type.
    'no element type' => $hover('foreach_no_element_type', new Expectation\NoAnswer()),
    'docblock names no known class' => $hover('foreach_func_call_unknown', new Expectation\NoAnswer()),
    'method call on unresolvable receiver' => $hover('foreach_method_call_unresolvable', new Expectation\NoAnswer()),
    'property fetch on unresolvable receiver' => $hover(
        'foreach_property_fetch_unresolvable',
        new Expectation\NoAnswer(),
    ),
];
