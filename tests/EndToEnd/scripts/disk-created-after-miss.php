<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$consumer = 'src/DiskChange/Consumer.php';
$late = 'src/DiskChange/Late.php';
$newLate = new Marker\SymbolMarker('new_late');

// A class that was missing is not remembered as missing once it is created.
return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Definition($consumer, $newLate, expect: new Expectation\NoAnswer()),
        new Step\Copy('DiskChange/Late.php', $late),
        new Step\ReportChanges([new Step\FileChange($late, Step\FileChangeType::Created)]),
        new Step\Definition($consumer, $newLate, expect: new Expectation\LandsOn($late, line: 11)),
    ],
    capabilities: new Session\ClientCapabilities(watchedFilesDynamicRegistration: true),
);
