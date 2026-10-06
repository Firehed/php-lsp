<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'PresenterParity.php';
$description = 'Doubles the input number.';

$script = fn (Step\StepInterface $ask): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [new Step\Open($file), $ask],
);

// Every surface shows a docblock's description, never its tags.
return [
    'hover' => $script(new Step\Hover(
        $file,
        new Marker\SymbolMarker('presenter_hover'),
        expect: [new Expectation\Shows($description), new Expectation\Hides('@param')],
    )),
    'signature help' => $script(new Step\SignatureHelp(
        $file,
        new Marker\CursorMarker('presenter_sig'),
        expect: new Expectation\DocumentationIs($description),
    )),
    'completion' => $script(new Step\Complete(
        $file,
        new Marker\CursorMarker('presenter_completion'),
        expect: new Expectation\Documents('presenterParityDouble', $description),
    )),
];
