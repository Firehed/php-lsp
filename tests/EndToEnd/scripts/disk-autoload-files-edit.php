<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\ClientCapabilities;
use Firehed\PhpLsp\Tests\EndToEnd\Copy;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\FileChange;
use Firehed\PhpLsp\Tests\EndToEnd\FileChangeType;
use Firehed\PhpLsp\Tests\EndToEnd\ReportChanges;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\SymbolMarker;

$consumer = 'src/DiskChange/Consumer.php';
$helper = new SymbolMarker('helper_added');

// Functions in an `autoload.files` entry are found through an index of that
// file, which an edit must refresh.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Ask(Feature::Definition, $consumer, $helper),
        new Copy('DiskChange/helpers.with-added.php', 'AutoloadFiles/helpers.php'),
        new ReportChanges([new FileChange('AutoloadFiles/helpers.php', FileChangeType::Changed)]),
        new Ask(Feature::Definition, $consumer, $helper),
    ],
    capabilities: new ClientCapabilities(watchedFilesDynamicRegistration: true),
);
