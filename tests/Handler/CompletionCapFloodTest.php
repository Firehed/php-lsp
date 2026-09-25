<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Handler\CompletionHandler;
use Firehed\PhpLsp\Handler\TextDocumentSyncHandler;
use Firehed\PhpLsp\Index\ComposerAutoloadMap;
use Firehed\PhpLsp\Knowledge\KnowledgeStack;
use Firehed\PhpLsp\Protocol\NotificationMessage;
use Firehed\PhpLsp\Protocol\RequestMessage;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Resolution\TypeSource\NativeTypeSource;
use Firehed\PhpLsp\Tests\Completion\WiresCompletionSourceTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\TestCase;

/**
 * A user typing a common function prefix in expression context must still see
 * the built-in functions that match it, even when the workspace autoload
 * holds many class-likes that share the prefix.
 *
 * The completion response is capped at CompletionHandler::RESULT_LIMIT items
 * and sort-then-truncated by `sortText ?? label`. Class labels are typically
 * PascalCase and built-in function labels are lowercase, so uppercase-first
 * ASCII order lets class-likes take every top slot in the cap and push the
 * functions off. Any change that widens the class-like set reaching the
 * global-scope response makes this cap tighter for the same prefix.
 *
 * The critical position is the *global* scope. PHP's own name-resolution
 * rule 4 makes every namespace a sub-namespace of the global one, so a
 * class-like in any namespace reaches the global-scope resolver as a
 * reachable sub-namespace reference. A prefix search that returns a large
 * set of class-likes therefore reaches the response as-is: the reachability
 * filter cannot save the cap.
 *
 * The fixture builds a hand-crafted `ComposerAutoloadMap` over a temp tree
 * of class files spread across many nested vendor-like namespaces, so the
 * flood is real (files on disk that Composer would autoload) rather than
 * simulated. `tests/Fixtures/` is deliberately left alone: the flood is a
 * stress case for the cap, not shared workspace state.
 *
 * These tests assert the surviving offerings, not the exact count. Freezing
 * a count is version-fragile (PHP's `str_*` family evolves across supported
 * versions); freezing the presence of specific long-stable names — and the
 * order of magnitude of the surviving family — is not.
 */
final class CompletionCapFloodTest extends TestCase
{
    use WiresCompletionSourceTrait;

    private const int FLOOD_PER_VENDOR = 30;
    private const string PROBE_URI = 'file:///probe.php';

    /**
     * Vendor-shaped namespaces mirroring how real `vendor/` layouts stack
     * nested paths. Each contributes {@see self::FLOOD_PER_VENDOR} class-likes
     * that all match the "str" prefix.
     *
     * @var list<string>
     */
    private const array VENDOR_NAMESPACES = [
        'Vendor\\Alpha\\Support',
        'Vendor\\Beta\\Streams',
        'Vendor\\Gamma\\Text',
        'Vendor\\Delta\\Helpers',
        'Vendor\\Epsilon\\Domain',
    ];

    private string $projectRoot;
    private DocumentManager $documents;
    private CompletionHandler $handler;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/php-lsp-flood-' . bin2hex(random_bytes(6));
        $this->projectRoot = $root;

        $psr4 = [];
        foreach (self::VENDOR_NAMESPACES as $namespace) {
            $directory = $root . '/' . strtr($namespace, '\\', '/');
            self::assertTrue(mkdir($directory, 0777, true), "the vendor directory {$namespace} must be creatable");
            $psr4[$namespace . '\\'] = [$directory];

            for ($i = 0; $i < self::FLOOD_PER_VENDOR; $i++) {
                $name = sprintf('Str%03d', $i);
                $written = file_put_contents(
                    $directory . '/' . $name . '.php',
                    "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nclass {$name}\n{\n}\n",
                );
                self::assertNotFalse($written, "the synthetic class {$namespace}\\{$name} must be writable");
            }
        }

        $map = new ComposerAutoloadMap(psr4: $psr4);
        $production = ProductionSyntaxSource::create();
        $knowledge = KnowledgeStack::forProject($map, $production->source, $production->reader);

        $memberResolver = new MemberResolver($knowledge->source);
        $typeSource = new NativeTypeSource($knowledge->source, $memberResolver);
        $resolver = new SymbolResolver(
            $production->source,
            $knowledge->source,
            $memberResolver,
            $typeSource,
        );

        $capabilities = self::createStub(SessionCapabilitiesProviderInterface::class);
        $capabilities->method('getSessionCapabilities')
            ->willReturn(new SessionCapabilities(snippetSupport: false));

        $this->documents = new DocumentManager();
        $this->handler = new CompletionHandler(
            $this->documents,
            self::completionSourceFor($knowledge->source, $resolver, $capabilities),
        );
        $this->syncHandler = new TextDocumentSyncHandler($this->documents, $knowledge->sink);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->projectRoot);
    }

    /**
     * The critical position: the probe is at the global namespace, so PHP's
     * rule 4 treats every vendor namespace as a reachable sub-namespace and
     * every synthetic `Str*` class in the vendor tree reaches the response.
     * A wide sample of built-in `str_*` functions must still survive the cap:
     * the user is typing a function prefix at this position.
     */
    public function testBuiltinStringFunctionsSurviveClassLikeFloodAtGlobalScope(): void
    {
        $labels = $this->completeAt("<?php\n\$x = str", line: 1, character: 8);

        $surviving = self::stringFunctionsIn($labels);
        self::assertGreaterThanOrEqual(
            5,
            count($surviving),
            'the built-in str_* family must reach the global-scope completion response even '
            . 'when the workspace autoload holds many class-likes that share the "str" prefix; '
            . 'the current response held: ' . implode(', ', $surviving),
        );

        // A modern, long-stable member of the family: if any str_* function
        // survives, str_contains is one of the ones the sort keeps. Its
        // absence is a signal the family was truncated entirely.
        self::assertContains(
            'str_contains',
            $labels,
            'str_contains must reach the global-scope completion response for the "str" prefix; '
            . 'a class-like flood must not push the whole str_* family off the response cap',
        );
    }

    /**
     * The same probe but in an unrelated namespace: PHP's rule 4 still makes
     * every vendor namespace reachable through its top-level prefix
     * (`Vendor\Alpha\Support\Str001` reaches a probe in `App\Controllers` as
     * `\Vendor\Alpha\Support\Str001`), so the flood still applies. The
     * built-in `str_*` functions must survive the cap here too.
     */
    public function testBuiltinStringFunctionsSurviveClassLikeFloodInNamespacedScope(): void
    {
        $labels = $this->completeAt(
            "<?php\nnamespace App\\Controllers;\n\$x = str",
            line: 2,
            character: 8,
        );

        $surviving = self::stringFunctionsIn($labels);
        self::assertGreaterThanOrEqual(
            5,
            count($surviving),
            'the built-in str_* family must reach the completion response even when the '
            . 'workspace autoload holds many class-likes that share the "str" prefix in other '
            . 'namespaces; the current response held: ' . implode(', ', $surviving),
        );
        self::assertContains(
            'str_contains',
            $labels,
            'str_contains must reach the completion response for the "str" prefix in a '
            . 'namespaced scope; a class-like flood must not push the whole str_* family off '
            . 'the response cap',
        );
    }

    /**
     * Inside a call — `foo(str` — the completion is asking for expressions to
     * pass as arguments. Built-in functions belong in the response, and the
     * class-like flood must not push them out of the cap here either.
     */
    public function testBuiltinStringFunctionsSurviveClassLikeFloodInsideCallContext(): void
    {
        $labels = $this->completeAt(
            "<?php\nfunction consume(callable \$c): void {}\nconsume(str",
            line: 2,
            character: 11,
        );

        $surviving = self::stringFunctionsIn($labels);
        self::assertGreaterThanOrEqual(
            5,
            count($surviving),
            'the built-in str_* family must reach the call-context completion response even '
            . 'when many class-likes share the "str" prefix in the workspace autoload; '
            . 'the current response held: ' . implode(', ', $surviving),
        );
        self::assertContains(
            'str_contains',
            $labels,
            'str_contains must reach the call-context completion response for the "str" prefix; '
            . 'a class-like flood must not push the whole str_* family off the response cap',
        );
    }

    /**
     * Only names built into PHP are counted, and only when they start with
     * `str_`. The classic `str*` family (`strlen`, `strpos`, …) sorts after
     * `str_*` in ASCII, so a class-like flood pushes it off first: a
     * response that still holds any `str_*` name is proof the whole family
     * did not collapse.
     *
     * @param list<string> $labels
     * @return list<string>
     */
    private static function stringFunctionsIn(array $labels): array
    {
        return array_values(array_filter(
            $labels,
            static fn(string $label): bool => str_starts_with($label, 'str_')
                && function_exists($label),
        ));
    }

    /**
     * @return list<string>
     */
    private function completeAt(string $probeSource, int $line, int $character): array
    {
        $this->openDocument(self::PROBE_URI, $probeSource);

        $request = RequestMessage::fromArray([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'textDocument/completion',
            'params' => [
                'textDocument' => ['uri' => self::PROBE_URI],
                'position' => ['line' => $line, 'character' => $character],
            ],
        ]);
        $result = $this->handler->handle($request);
        self::assertIsArray($result, 'a completion request over a probe document must return a result');
        self::assertArrayHasKey('items', $result, 'the completion result must carry an items list');

        return array_column($result['items'], 'label');
    }

    private function openDocument(string $uri, string $code): void
    {
        $this->syncHandler->handle(NotificationMessage::fromArray([
            'jsonrpc' => '2.0',
            'method' => 'textDocument/didOpen',
            'params' => [
                'textDocument' => [
                    'uri' => $uri,
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => $code,
                ],
            ],
        ]));
    }

    private static function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
