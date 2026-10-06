<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$consumer = 'src/DiskChange/Consumer.php';
$helper = new Marker\SymbolMarker('helper_added');

// The index of `autoload.files` entries is rebuilt only when the editor
// reports a change, so an unreported edit is not seen.
return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Definition($consumer, $helper, expect: new Expectation\NoAnswer()),
        new Step\Copy('DiskChange/helpers.with-added.php', 'AutoloadFiles/helpers.php'),
        new Step\Definition($consumer, $helper, expect: new Expectation\NoAnswer()),
    ],
    capabilities: new Session\ClientCapabilities(watchedFilesDynamicRegistration: true),
);
