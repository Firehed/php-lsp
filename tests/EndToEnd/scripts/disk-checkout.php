<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$consumer = 'src/DiskChange/Consumer.php';
$widget = 'src/DiskChange/Widget.php';
$gadget = 'src/DiskChange/Gadget.php';
$greet = new Marker\SymbolMarker('widget_greet');
$spin = new Marker\SymbolMarker('gadget_spin');

// A checkout changes several files, reported together.
return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Definition($consumer, $greet, expect: new Expectation\NoAnswer()),
        new Step\Definition($consumer, $spin, expect: new Expectation\NoAnswer()),
        new Step\Copy('DiskChange/Widget.with-greet.php', $widget),
        new Step\Copy('DiskChange/Gadget.with-spin.php', $gadget),
        new Step\ReportChanges([
            new Step\FileChange($widget, Step\FileChangeType::Changed),
            new Step\FileChange($gadget, Step\FileChangeType::Changed),
        ]),
        new Step\Definition($consumer, $greet, expect: new Expectation\LandsOn($widget, line: 12)),
        new Step\Definition($consumer, $spin, expect: new Expectation\LandsOn($gadget, line: 12)),
    ],
    capabilities: new Session\ClientCapabilities(watchedFilesDynamicRegistration: true),
);
