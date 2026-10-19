<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$user = 'src/Domain/User.php';

// Each marker is the start of a called member's name, right after -> or ::.
$at = fn (Step\StepInterface $step): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [new Step\Open($user), $step],
);
$setName = new Marker\SymbolMarker('setName');
$create = new Marker\SymbolMarker('create');
$markCreated = new Marker\SymbolMarker('markCreated');

return [
    'completion at an instance method call' => $at(new Step\Complete($user, $setName, expect: [
        new Expectation\Offers('setName'),
        new Expectation\Withholds('DEFAULT_ROLE'),
    ])),
    'completion at a static method call' => $at(new Step\Complete($user, $create, expect: [
        new Expectation\Offers('create'),
        new Expectation\Withholds('manager'),
    ])),
    'completion at a trait method call' => $at(new Step\Complete($user, $markCreated, expect: [
        new Expectation\Offers('markCreated'),
        new Expectation\Withholds('DEFAULT_ROLE'),
    ])),
];
