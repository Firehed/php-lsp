<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$consumer = 'ComposerRegeneration/Consumer.php';
$regenWidget = new Marker\SymbolMarker('regen_widget');
$regenPrefix = new Marker\CursorMarker('regen_prefix');
$userConsumer = 'SignatureHelp.php';
$newUser = new Marker\SymbolMarker('class_instantiation');
$catalog = 'Namespacing/CatalogProbe.php';
$userPrefix = new Marker\CursorMarker('ondisk_class');
$constantConsumer = 'src/Resolution/NamespacedConstant.php';
$helperConstant = new Marker\SymbolMarker('global_const_fetch');
$capabilities = new Session\ClientCapabilities(watchedFilesDynamicRegistration: true);

// A `composer install` rewrites the maps under vendor/composer/ and the client
// reports them; the next query must use the new maps.
$psr4 = 'vendor/composer/autoload_psr4.php';
$psr4Regenerated = [
    new Step\Copy('ComposerRegeneration/autoload_psr4.php', $psr4),
    new Step\ReportChanges([new Step\FileChange($psr4, Step\FileChangeType::Changed)]),
];
$files = 'vendor/composer/autoload_files.php';

return [
    'a regenerated PSR-4 map reaches lookup' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Definition($consumer, $regenWidget, expect: new Expectation\NoAnswer()),
            new Step\Definition(
                $userConsumer,
                $newUser,
                expect: new Expectation\LandsOn('src/Domain/User.php', line: 15),
            ),
            ...$psr4Regenerated,
            new Step\Definition(
                $consumer,
                $regenWidget,
                expect: new Expectation\LandsOn('ComposerRegeneration/src/Widget.php', line: 7),
            ),
            new Step\Definition($userConsumer, $newUser, expect: new Expectation\NoAnswer()),
        ],
        capabilities: $capabilities,
    ),
    'a regenerated PSR-4 map reaches enumeration' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Complete($consumer, $regenPrefix, expect: [new Expectation\NoAnswer()]),
            new Step\Complete($catalog, $userPrefix, expect: [new Expectation\Offers('User')]),
            ...$psr4Regenerated,
            new Step\Complete($consumer, $regenPrefix, expect: [new Expectation\Offers('Widget')]),
            new Step\Complete($catalog, $userPrefix, expect: [new Expectation\NoAnswer()]),
        ],
        capabilities: $capabilities,
    ),
    'a regenerated files list reaches the derived index' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Definition(
                $constantConsumer,
                $helperConstant,
                expect: new Expectation\LandsOn('AutoloadFiles/helpers.php', line: 19),
            ),
            new Step\Copy('ComposerRegeneration/autoload_files.php', $files),
            new Step\ReportChanges([new Step\FileChange($files, Step\FileChangeType::Changed)]),
            new Step\Definition($constantConsumer, $helperConstant, expect: new Expectation\NoAnswer()),
        ],
        capabilities: $capabilities,
    ),
];
