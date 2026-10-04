<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\ClientCapabilities;
use Firehed\PhpLsp\Tests\EndToEnd\Copy;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\SymbolMarker;

$consumer = 'src/DiskChange/Consumer.php';
$greet = new SymbolMarker('widget_greet');

// What a file declares is remembered by its text, so a class file is read
// again on the next query and an unreported edit is still seen.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Ask(Feature::Definition, $consumer, $greet),
        new Copy('DiskChange/Widget.with-greet.php', 'src/DiskChange/Widget.php'),
        new Ask(Feature::Definition, $consumer, $greet),
    ],
    capabilities: new ClientCapabilities(watchedFilesDynamicRegistration: true),
);
