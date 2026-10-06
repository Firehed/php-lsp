<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$consumer = 'src/DiskChange/Consumer.php';
$widget = 'src/DiskChange/Widget.php';
$greet = new Marker\SymbolMarker('widget_greet');

// What a file declares is remembered by its text, so a class file is read
// again on the next query and an unreported edit is still seen.
return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Definition($consumer, $greet, expect: new Expectation\NoAnswer()),
        new Step\Copy('DiskChange/Widget.with-greet.php', $widget),
        new Step\Definition($consumer, $greet, expect: new Expectation\LandsOn($widget, line: 12)),
    ],
    capabilities: new Session\ClientCapabilities(watchedFilesDynamicRegistration: true),
);
