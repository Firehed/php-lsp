<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

// Nothing is opened: a server answers for a document whether or not the
// editor has it open ([LSP] textDocument/didOpen).
return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        // The declaration starts at its attribute group.
        new Step\Definition(
            'src/Services/ApiController.php',
            new Marker\SymbolMarker('attr_class'),
            expect: new Expectation\LandsOn('src/Attributes/Route.php', line: 12),
        ),
    ],
);
