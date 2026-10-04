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
$spin = new SymbolMarker('gadget_spin');

// A checkout changes several files, reported together.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Ask(Feature::Definition, $consumer, $greet),
        new Ask(Feature::Definition, $consumer, $spin),
        new Copy('DiskChange/Widget.with-greet.php', 'src/DiskChange/Widget.php'),
        new Copy('DiskChange/Gadget.with-spin.php', 'src/DiskChange/Gadget.php'),
        new ReportChanges([
            new FileChange('src/DiskChange/Widget.php', FileChangeType::Changed),
            new FileChange('src/DiskChange/Gadget.php', FileChangeType::Changed),
        ]),
        new Ask(Feature::Definition, $consumer, $greet),
        new Ask(Feature::Definition, $consumer, $spin),
    ],
    capabilities: new ClientCapabilities(watchedFilesDynamicRegistration: true),
);
