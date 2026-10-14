<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$consumer = 'ComposerRegeneration/Consumer.php';
$regenWidget = new Marker\SymbolMarker('regen_widget');
$regenPrefix = new Marker\CursorMarker('regen_prefix');
$user = 'src/Domain/User.php';
$capabilities = new Session\ClientCapabilities(watchedFilesDynamicRegistration: true);

// A `composer install` rewrites the maps under vendor/composer/ and the client
// reports them; the next query must use the new maps.
$psr4 = 'vendor/composer/autoload_psr4.php';
$psr4Regenerated = [
    new Step\Copy('ComposerRegeneration/autoload_psr4.php', $psr4),
    new Step\ReportChanges([new Step\FileChange($psr4, Step\FileChangeType::Changed)]),
];
$files = 'vendor/composer/autoload_files.php';
$filesRegenerated = [
    new Step\Copy('ComposerRegeneration/autoload_files.php', $files),
    new Step\ReportChanges([new Step\FileChange($files, Step\FileChangeType::Changed)]),
];

return [
    'a regenerated PSR-4 map reaches lookup' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Definition($consumer, $regenWidget, expect: new Expectation\NoAnswer()),
            new Step\Definition(
                'SignatureHelp.php',
                new Marker\SymbolMarker('class_instantiation'),
                expect: new Expectation\LandsOn($user, line: 15),
            ),
            ...$psr4Regenerated,
            new Step\Definition(
                $consumer,
                $regenWidget,
                expect: new Expectation\LandsOn('ComposerRegeneration/src/Widget.php', line: 7),
            ),
            new Step\Definition(
                'SignatureHelp.php',
                new Marker\SymbolMarker('class_instantiation'),
                expect: new Expectation\NoAnswer(),
            ),
        ],
        capabilities: $capabilities,
    ),
    'a regenerated PSR-4 map reaches enumeration' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Complete($consumer, $regenPrefix, expect: [new Expectation\NoAnswer()]),
            new Step\Complete(
                'Namespacing/CatalogProbe.php',
                new Marker\CursorMarker('ondisk_class'),
                expect: [new Expectation\Offers('User')],
            ),
            ...$psr4Regenerated,
            new Step\Complete($consumer, $regenPrefix, expect: [new Expectation\Offers('Widget')]),
            new Step\Complete(
                'Namespacing/CatalogProbe.php',
                new Marker\CursorMarker('ondisk_class'),
                expect: [new Expectation\NoAnswer()],
            ),
        ],
        capabilities: $capabilities,
    ),
    'a regenerated files list reaches the derived index' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Definition(
                'src/Resolution/NamespacedConstant.php',
                new Marker\SymbolMarker('global_const_fetch'),
                expect: new Expectation\LandsOn('AutoloadFiles/helpers.php', line: 19),
            ),
            ...$filesRegenerated,
            new Step\Definition(
                'src/Resolution/NamespacedConstant.php',
                new Marker\SymbolMarker('global_const_fetch'),
                expect: new Expectation\NoAnswer(),
            ),
        ],
        capabilities: $capabilities,
    ),
];
