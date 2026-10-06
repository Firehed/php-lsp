<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$consumer = 'src/DiskChange/Consumer.php';
$widget = 'src/DiskChange/Widget.php';
$greet = new Marker\SymbolMarker('widget_greet');

// While a file is open its buffer wins over the disk ([LSP]
// textDocument/didOpen). Closing it means the disk is read again, not the
// answer from before it was opened.
return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Definition($consumer, $greet, expect: new Expectation\NoAnswer()),
        new Step\Open($widget),
        new Step\Copy('DiskChange/Widget.with-greet.php', $widget),
        new Step\Definition($consumer, $greet, expect: new Expectation\NoAnswer()),
        new Step\Close($widget),
        new Step\Definition($consumer, $greet, expect: new Expectation\LandsOn($widget, line: 12)),
    ],
    capabilities: new Session\ClientCapabilities(watchedFilesDynamicRegistration: true),
);
