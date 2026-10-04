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
$late = new SymbolMarker('new_late');

// A class that was missing is not remembered as missing once it is created.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Ask(Feature::Definition, $consumer, $late),
        new Copy('DiskChange/Late.php', 'src/DiskChange/Late.php'),
        new ReportChanges([new FileChange('src/DiskChange/Late.php', FileChangeType::Created)]),
        new Ask(Feature::Definition, $consumer, $late),
    ],
    capabilities: new ClientCapabilities(watchedFilesDynamicRegistration: true),
);
