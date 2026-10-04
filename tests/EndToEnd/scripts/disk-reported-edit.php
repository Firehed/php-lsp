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
$greet = new SymbolMarker('widget_greet');

// Another program edits a file the editor never opened.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Ask(Feature::Definition, $consumer, $greet),
        new Copy('DiskChange/Widget.with-greet.php', 'src/DiskChange/Widget.php'),
        new ReportChanges([new FileChange('src/DiskChange/Widget.php', FileChangeType::Changed)]),
        new Ask(Feature::Definition, $consumer, $greet),
    ],
    capabilities: new ClientCapabilities(watchedFilesDynamicRegistration: true),
);
