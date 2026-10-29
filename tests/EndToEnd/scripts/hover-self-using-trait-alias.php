<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

// The alias's source is looked up among the traits the trait uses, which is
// itself, so resolving the call must not recurse without end.
$file = 'EdgeCases/SelfUsingTraitAlias.php';

return new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover($file, new Marker\SymbolMarker('self_alias_call'), expect: new Expectation\Shows('run')),
    ],
);
