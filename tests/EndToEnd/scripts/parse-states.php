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
$inCall = new Marker\CursorMarker('in_call');

$scripts = [];
foreach ($states as $state => [$file, $class]) {
    $scripts["{$state}: hover on \$this shows the enclosing class"] = new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open($file),
            new Step\Hover($file, $thisVariable, expect: new Expectation\Shows($class)),
        ],
    );
    $scripts["{$state}: \$this is offered as the enclosing class"] = new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open($file),
            new Step\Complete($file, $thisStart, expect: [new Expectation\Details('$this', $class)]),
        ],
    );
    $scripts["{$state}: \$this-> offers the class's members"] = new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open($file),
            new Step\Complete($file, $inCall, expect: [new Expectation\Offers('name')]),
        ],
    );
    $scripts["{$state}: signature help inside a call"] = new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open($file),
            new Step\SignatureHelp($file, $inCall, expect: new Expectation\SignatureShows('strlen')),
        ],
    );
}

return $scripts;
