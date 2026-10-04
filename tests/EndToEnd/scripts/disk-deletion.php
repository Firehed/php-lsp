<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\ClientCapabilities;
use Firehed\PhpLsp\Tests\EndToEnd\Delete;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\FileChange;
use Firehed\PhpLsp\Tests\EndToEnd\FileChangeType;
use Firehed\PhpLsp\Tests\EndToEnd\ReportChanges;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\SymbolMarker;

$consumer = 'src/DiskChange/Consumer.php';
$widget = new SymbolMarker('new_widget');

return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Ask(Feature::Definition, $consumer, $widget),
        new Delete('src/DiskChange/Widget.php'),
        new ReportChanges([new FileChange('src/DiskChange/Widget.php', FileChangeType::Deleted)]),
        new Ask(Feature::Definition, $consumer, $widget),
    ],
    capabilities: new ClientCapabilities(watchedFilesDynamicRegistration: true),
);
