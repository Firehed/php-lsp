<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$consumer = 'src/Definition/BufferDeclared.php';
$declarations = 'BufferChange/Declarations.php';
$original = new Marker\SymbolMarker('original');
$renamed = new Marker\SymbolMarker('renamed');
$widgetConsumer = 'src/DiskChange/Consumer.php';
$widget = 'src/DiskChange/Widget.php';
$newWidget = new Marker\SymbolMarker('new_widget');

$opened = [
    new Step\Definition($consumer, $original, expect: new Expectation\NoAnswer()),
    new Step\Open($declarations),
    new Step\Definition($consumer, $original, expect: new Expectation\LandsOn($declarations, line: 10)),
];

return [
    'opening adds what the buffer declares' => new Session\Script(
        project: 'tests/Fixtures',
        steps: $opened,
    ),
    'a change replaces what the buffer declares' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            ...$opened,
            new Step\Replace($declarations, 'BufferChange/Declarations.renamed.php'),
            new Step\Definition($consumer, $original, expect: new Expectation\NoAnswer()),
            new Step\Definition($consumer, $renamed, expect: new Expectation\LandsOn($declarations, line: 7)),
        ],
    ),
    'closing removes what the buffer declared' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            ...$opened,
            new Step\Close($declarations),
            new Step\Definition($consumer, $original, expect: new Expectation\NoAnswer()),
        ],
    ),
    'a buffer that drops a class on disk hides it' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Definition($widgetConsumer, $newWidget, expect: new Expectation\LandsOn($widget, line: 11)),
            new Step\Open($widget),
            new Step\Replace($widget, $declarations),
            new Step\Definition($widgetConsumer, $newWidget, expect: new Expectation\NoAnswer()),
        ],
    ),
];
