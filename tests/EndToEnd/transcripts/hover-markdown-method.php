<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$file = 'src/Domain/User.php';

// A client that prefers markdown gets markdown hover content ([LSP]
// HoverClientCapabilities). The transcript locks the fenced format.
return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover($file, new Marker\SymbolMarker('setName'), expect: [
            new Expectation\Formatted(Result\MarkupKind::Markdown),
            new Expectation\Shows('setName', 'Updates the user'),
        ]),
    ],
    capabilities: new Session\ClientCapabilities(markdownHover: true),
);
