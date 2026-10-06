<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$user = 'src/Domain/User.php';

$hover = fn (string $marker, Expectation\HoverExpectationInterface $expect): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($user),
        new Step\Hover($user, new Marker\SymbolMarker($marker), expect: $expect),
    ],
);

return [
    'method' => $hover('setName', new Expectation\Shows('setName', 'Updates the user')),
    'property' => $hover('manager', new Expectation\Shows('$manager', 'User')),
    'static method' => $hover(
        'create',
        new Expectation\Shows('public static function create(', 'Creates a new user instance', 'Fixtures\Domain\User'),
    ),
    'nullsafe method call' => $hover('setName_nullsafe', new Expectation\Shows('setName', 'Updates the user')),
    'nullsafe property fetch' => $hover('manager_nullsafe', new Expectation\Shows('$manager', 'User')),
    'typed variable' => $hover('variable_typed', new Expectation\Shows('$typed', 'User')),
    'promoted property' => $hover('promoted_property', new Expectation\Shows('$id', 'string', 'readonly')),
    'trait method' => $hover('markCreated', new Expectation\Shows('markCreated')),
    'trait property' => $hover('trait_property_consumer', new Expectation\Shows('$createdAt', 'DateTimeImmutable')),
    'chain method' => $hover('chain_method', new Expectation\Shows('withName', 'Sets the name fluently')),
    'chain property' => $hover('chain_property', new Expectation\Shows('$team', 'Team')),
    'chain into another type' => $hover('chain_cross_type', new Expectation\Shows('getLeader', 'Gets the team leader')),
    'chain back to User' => $hover('chain_back_to_user', new Expectation\Shows('$manager', 'User')),
    'chain nullsafe' => $hover('chain_nullsafe', new Expectation\Shows('withAge', 'Sets the age fluently')),
];
