<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$user = 'src/Domain/User.php';
$timestamps = 'src/Traits/HasTimestamps.php';

$definition = fn (
    string $marker,
    Expectation\DefinitionExpectationInterface $expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($user),
        new Step\Definition($user, new Marker\SymbolMarker($marker), expect: $expect),
    ],
);

return [
    'static method' => $definition('create', new Expectation\LandsOn($user, line: 124)),
    'instance method' => $definition('setName', new Expectation\LandsOn($user, line: 48)),
    'method via assignment' => $definition('method_via_assignment', new Expectation\LandsOn($user, line: 48)),
    'nullsafe method' => $definition('setName_nullsafe', new Expectation\LandsOn($user, line: 48)),
    'nullsafe method via assignment' => $definition(
        'nullsafe_via_assignment',
        new Expectation\LandsOn($user, line: 48),
    ),
    'property' => $definition('manager', new Expectation\LandsOn($user, line: 29)),
    'promoted property' => $definition('promoted_property', new Expectation\LandsOn($user, line: 24)),
    'trait method' => $definition('markCreated', new Expectation\LandsOn($timestamps, line: 39)),
    'trait property' => $definition('trait_property_consumer', new Expectation\LandsOn($timestamps, line: 17)),
    'unknown class' => $definition('unknown_class', new Expectation\NoAnswer()),
];
