<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$complete = fn (
    string $file,
    string $marker,
    Expectation\CompletionExpectationInterface ...$expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Complete($file, new Marker\CursorMarker($marker), expect: array_values($expect)),
    ],
);
$afterOpening = fn (Session\Script $script, string ...$files): Session\Script => new Session\Script(
    project: $script->project,
    steps: [
        ...array_map(static fn (string $file): Step\Open => new Step\Open($file), array_values($files)),
        ...$script->steps,
    ],
);

$unqualified = 'Namespacing/UnqualifiedNewCompletion.php';
$imported = 'Namespacing/ImportedPrefix.php';
$catalog = 'Namespacing/CatalogProbe.php';
$statement = 'src/Completion/Keywords.php';
$statementCursor = new Marker\CursorMarker('statement');

// Each fixture position follows `\Ps`; the inlined Psr\Http\ node shows navigation ran.
$navigates = fn (string $marker): Session\Script => $complete(
    'Namespacing/AbsoluteNavigation.php',
    $marker,
    new Expectation\Offers('Psr\Http\\'),
);

return [
    'current namespace class is offered bare' => $afterOpening(
        $complete(
            $unqualified,
            'unqualified_new',
            new Expectation\Details('Theme', 'App\Theme'),
            new Expectation\Withholds('Thing', '\Other\Thing', 'Other\Thing'),
        ),
        'Namespacing/UnrelatedNamespaceClass.php',
    ),
    'sub-namespace classes are offered by their relative names' => $afterOpening(
        $complete(
            $unqualified,
            'subnamespace_new',
            new Expectation\Details('Sub\Thing', 'App\Sub\Thing'),
            new Expectation\Details('Deep\Thing', 'App\Deep\Thing'),
            new Expectation\Withholds('Thing'),
        ),
        'Namespacing/SubNamespaceClass.php',
        'Namespacing/SecondSubNamespaceClass.php',
    ),
    'built-in class is not offered bare in a namespace' => $complete(
        $unqualified,
        'builtin_new',
        new Expectation\Withholds('Exception'),
    ),
    'current namespace class on disk is offered unopened' => $complete(
        'Namespacing/CurrentNamespaceBareProbe.php',
        'current_ns_bare',
        new Expectation\Details('User', 'Fixtures\Domain\User'),
    ),
    'absolute prefix inlines a namespace with one child' => $complete(
        $unqualified,
        'nav_global',
        new Expectation\Offers('Psr\Http\\'),
        new Expectation\Withholds('Psr\\'),
    ),
    'absolute prefix offers a global built-in class' => $complete(
        $unqualified,
        'nav_global_class',
        new Expectation\Details('SplFixedArray', 'SplFixedArray'),
    ),
    'absolute prefix offers global functions in an expression' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open($statement),
            new Step\Type($statement, $statementCursor, '\strle'),
            new Step\Complete($statement, $statementCursor, expect: [new Expectation\Offers('strlen')]),
        ],
    ),
    'absolute prefix navigates in a catch clause' => $navigates('catch_nav'),
    'absolute prefix navigates in a parameter type' => $navigates('param_nav'),
    'absolute prefix navigates in a return type' => $navigates('return_nav'),
    'absolute prefix navigates in an extends clause' => $navigates('extends_nav'),
    'absolute prefix navigates in an implements clause' => $navigates('implements_nav'),
    'absolute prefix navigates in a trait use' => $navigates('trait_use_nav'),
    'absolute prefix navigates in an attribute' => $navigates('attribute_nav'),
    'class on disk is offered unopened' => $complete(
        $catalog,
        'ondisk_class',
        new Expectation\Details('User', 'Fixtures\Domain\User'),
    ),
    'interface on disk is not offered after new' => $complete(
        $catalog,
        'ondisk_interface',
        new Expectation\Withholds('RequestInterface'),
    ),
    'directory listing phantom is not offered' => $complete(
        $catalog,
        'ondisk_phantom',
        new Expectation\Offers('Fixture'),
        new Expectation\Withholds('functions'),
    ),
    'class from an autoload files entry is offered' => $complete(
        $catalog,
        'ondisk_autoload_files',
        new Expectation\Offers('HelperRegistry'),
        new Expectation\Withholds('HelperContract'),
    ),
    'bare import of a small namespace inlines it' => $complete(
        $imported,
        'imported_bare',
        new Expectation\Details('Env\Repository', 'Fixtures\Model\Env\Repository'),
        new Expectation\Withholds('Env\\'),
    ),
    'bare import of a large namespace is a node' => $complete(
        $imported,
        'imported_large',
        new Expectation\Details('Message\\', 'Psr\Http\Message'),
    ),
    'qualified import prefix offers the child' => $complete(
        $imported,
        'imported_slash',
        new Expectation\Details('Repository', 'Fixtures\Model\Env\Repository'),
    ),
    'qualified import prefix with a partial child' => $complete(
        $imported,
        'imported_partial',
        new Expectation\Details('Repository', 'Fixtures\Model\Env\Repository'),
    ),
    'open child class does not leak its members' => $afterOpening(
        $complete(
            $imported,
            'imported_slash',
            new Expectation\Offers('Repository'),
            new Expectation\Withholds('persist'),
        ),
        'src/Model/Env/Repository.php',
    ),
    'qualified import prefix matching no child offers nothing' => $complete(
        $imported,
        'imported_no_match',
        new Expectation\NoAnswer(),
    ),
    'qualified import prefix withholds an interface after new' => $complete(
        $imported,
        'imported_iface_new',
        new Expectation\Withholds('Handler'),
    ),
    'qualified import prefix offers an interface as a type' => $complete(
        $imported,
        'imported_iface_type',
        new Expectation\Offers('Handler'),
    ),
    'qualified import prefix reaches a grandchild' => $complete(
        $imported,
        'imported_deep',
        new Expectation\Details('Thing', 'Fixtures\Model\Env\Sub\Thing'),
    ),
    'qualified prefix that is not an import or a child offers nothing' => $complete(
        $imported,
        'imported_unrelated',
        new Expectation\NoAnswer(),
    ),
    'qualified prefix from a current namespace child' => $complete(
        'Namespacing/CurrentNamespaceProbe.php',
        'current_ns_child',
        new Expectation\Details('Repository', 'Fixtures\Model\Env\Repository'),
    ),
];
