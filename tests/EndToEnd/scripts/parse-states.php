<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

// One scenario in three parse states: the file parses, the parser recovers
// from an error, or the parser yields nothing and only the text is left.
$states = [
    'clean' => ['src/ParseHealth/Clean.php', 'Fixtures\ParseHealth\Clean'],
    'recovered' => ['src/ParseHealth/Recovered.php', 'Fixtures\ParseHealth\Recovered'],
    'empty' => ['src/ParseHealth/EmptyParse.php', 'Fixtures\ParseHealth\EmptyParse'],
];
$thisStart = new Marker\CursorMarker('this_start');
$thisVariable = new Marker\VariableMarker('this_var');
$insideCall = new Marker\CursorMarker('this_in_if');

$scripts = [];
foreach ($states as $state => [$file, $class]) {
    $scripts["{$state}: hover on \$this shows the enclosing class"] = new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open($file),
            new Step\Hover($file, $thisVariable, expect: new Expectation\Shows($class)),
        ],
    );
    $scripts["{$state}: \$this is offered as a variable"] = new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open($file),
            new Step\Complete($file, $thisStart, expect: [new Expectation\Offers('$this')]),
        ],
    );
    $scripts["{$state}: signature help inside a call"] = new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open($file),
            new Step\SignatureHelp($file, $insideCall, expect: new Expectation\SignatureShows('strlen')),
        ],
    );
}

return $scripts;
