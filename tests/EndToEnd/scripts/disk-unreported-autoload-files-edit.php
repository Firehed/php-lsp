<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\ClientCapabilities;
use Firehed\PhpLsp\Tests\EndToEnd\Copy;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\SymbolMarker;

$consumer = 'src/DiskChange/Consumer.php';
$helper = new SymbolMarker('helper_added');

// The index of `autoload.files` entries is rebuilt only when the editor
// reports a change, so an unreported edit is not seen.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Ask(Feature::Definition, $consumer, $helper),
        new Copy('DiskChange/helpers.with-added.php', 'AutoloadFiles/helpers.php'),
        new Ask(Feature::Definition, $consumer, $helper),
    ],
    capabilities: new ClientCapabilities(watchedFilesDynamicRegistration: true),
);
