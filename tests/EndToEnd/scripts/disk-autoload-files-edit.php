<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$consumer = 'src/DiskChange/Consumer.php';
$helpers = 'AutoloadFiles/helpers.php';
$helper = new Marker\SymbolMarker('helper_added');

// Functions in an `autoload.files` entry are found through an index of that
// file, which an edit must refresh.
return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Definition($consumer, $helper, expect: new Expectation\NoAnswer()),
        new Step\Copy('DiskChange/helpers.with-added.php', $helpers),
        new Step\ReportChanges([new Step\FileChange($helpers, Step\FileChangeType::Changed)]),
        new Step\Definition($consumer, $helper, expect: new Expectation\LandsOn($helpers, line: 11)),
    ],
    capabilities: new Session\ClientCapabilities(watchedFilesDynamicRegistration: true),
);
