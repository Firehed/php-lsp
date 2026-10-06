<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$union = 'src/Union/UnionReceiver.php';
$intersection = 'src/Intersection/IntersectionReceiver.php';
$person = 'src/Domain/Person.php';
$entity = 'src/Domain/Entity.php';

$script = fn (string $file, Step\StepInterface $ask): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [new Step\Open($file), $ask],
);

// Entity declares only getId() and Person only getName(), so each member
// resolves through exactly one constituent of the receiver's type.
$cases = [];
foreach (['union' => $union, 'intersection' => $intersection] as $kind => $file) {
    $cases += [
        "{$kind}: hover member on second constituent" => $script($file, new Step\Hover(
            $file,
            new Marker\SymbolMarker("{$kind}_person_member"),
            expect: new Expectation\Shows('getName'),
        )),
        "{$kind}: hover member on first constituent" => $script($file, new Step\Hover(
            $file,
            new Marker\SymbolMarker("{$kind}_entity_member"),
            expect: new Expectation\Shows('getId'),
        )),
        "{$kind}: definition of member on second constituent" => $script($file, new Step\Definition(
            $file,
            new Marker\SymbolMarker("{$kind}_person_member"),
            expect: new Expectation\LandsOn($person, line: 15),
        )),
        "{$kind}: definition of member on first constituent" => $script($file, new Step\Definition(
            $file,
            new Marker\SymbolMarker("{$kind}_entity_member"),
            expect: new Expectation\LandsOn($entity, line: 12),
        )),
        "{$kind}: signature help for member on second constituent" => $script($file, new Step\SignatureHelp(
            $file,
            new Marker\CursorMarker("{$kind}_signature"),
            expect: new Expectation\SignatureShows('getName'),
        )),
        "{$kind}: completion offers every constituent's members" => $script($file, new Step\Complete(
            $file,
            new Marker\CursorMarker("{$kind}_completion"),
            expect: new Expectation\Offers('getId', 'getName'),
        )),
    ];
}

return $cases;
