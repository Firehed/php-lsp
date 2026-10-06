<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$consumer = 'src/DiskChange/Consumer.php';
$widget = 'src/DiskChange/Widget.php';
$greet = new Marker\SymbolMarker('widget_greet');

// Another program edits a file the editor never opened.
return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Definition($consumer, $greet, expect: new Expectation\NoAnswer()),
        new Step\Copy('DiskChange/Widget.with-greet.php', $widget),
        new Step\ReportChanges([new Step\FileChange($widget, Step\FileChangeType::Changed)]),
        new Step\Definition($consumer, $greet, expect: new Expectation\LandsOn($widget, line: 12)),
    ],
    capabilities: new Session\ClientCapabilities(watchedFilesDynamicRegistration: true),
);
