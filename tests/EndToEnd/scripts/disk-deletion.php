<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$consumer = 'src/DiskChange/Consumer.php';
$widget = 'src/DiskChange/Widget.php';
$newWidget = new Marker\SymbolMarker('new_widget');

return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Definition($consumer, $newWidget, expect: new Expectation\LandsOn($widget, line: 11)),
        new Step\Delete($widget),
        new Step\ReportChanges([new Step\FileChange($widget, Step\FileChangeType::Deleted)]),
        new Step\Definition($consumer, $newWidget, expect: new Expectation\NoAnswer()),
    ],
    capabilities: new Session\ClientCapabilities(watchedFilesDynamicRegistration: true),
);
