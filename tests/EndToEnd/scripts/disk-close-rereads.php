<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Ask;
use Firehed\PhpLsp\Tests\EndToEnd\ClientCapabilities;
use Firehed\PhpLsp\Tests\EndToEnd\Close;
use Firehed\PhpLsp\Tests\EndToEnd\Copy;
use Firehed\PhpLsp\Tests\EndToEnd\Feature;
use Firehed\PhpLsp\Tests\EndToEnd\Open;
use Firehed\PhpLsp\Tests\EndToEnd\Script;
use Firehed\PhpLsp\Tests\EndToEnd\SymbolMarker;

$consumer = 'src/DiskChange/Consumer.php';
$widget = 'src/DiskChange/Widget.php';
$greet = new SymbolMarker('widget_greet');

// While a file is open its buffer wins over the disk ([LSP]
// textDocument/didOpen). Closing it means the disk is read again, not the
// answer from before it was opened.
return new Script(
    project: 'tests/Fixtures',
    steps: [
        new Ask(Feature::Definition, $consumer, $greet),
        new Open($widget),
        new Copy('DiskChange/Widget.with-greet.php', $widget),
        new Ask(Feature::Definition, $consumer, $greet),
        new Close($widget),
        new Ask(Feature::Definition, $consumer, $greet),
    ],
    capabilities: new ClientCapabilities(watchedFilesDynamicRegistration: true),
);
