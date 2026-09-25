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
 * A completion at a type-hint position must offer the built-in scalar types
 * (`string`, `int`, `bool`, `array`, …) even when the workspace autoload map
 * declares one directory under two overlapping PSR-4 prefixes.
 *
 * The overlap in real projects: `composer.json` commonly maps a package's own
 * namespace to `src/` and again to `tests/` (through the base prefix) *and*
 * a `Tests\` sub-namespace to `tests/`, so the test files are addressable by
 * both `App\Tests\Foo` and (accidentally) `App\Foo`. Under this branch's new
 * pre-index — a recursive walk of every PSR-4 root that files every candidate
 * FQN confirmed by Composer's loader — the same file gets filed under both
 * candidates. Only the specific-prefix entry names a real class; the other
 * points at a file whose class name it does not match.
 *
 * The false-positive entry reaches the type-hint predicate at completion
 * time. `ClassCandidateFilter::TypeHint` asks
 * `SymbolResolver::isValidTypeHint()` about it; the composite's disk backend
 * scans the file, sees no matching declaration, returns null; the fallback
 * built-in backend then reaches PHP reflection through `class_exists()`,
 * which triggers Composer's autoloader on the wrong FQN and can re-include
 * a file whose class is already loaded — a fatal error.
 *
 * The correct behaviour: no false-positive catalog entry, no fatal, and the
 * built-in types the user asked for at the type-hint position are in the
 * response.
 */
final class CompletionOverlappingPsr4Test extends TestCase
{
    use WiresCompletionSourceTrait;

    private const string PROBE_URI = 'file:///probe.php';

    private string $projectRoot;
    private DocumentManager $documents;
    private CompletionHandler $handler;
    private TextDocumentSyncHandler $syncHandler;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/php-lsp-overlap-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root . '/src', 0777, true), 'the src directory must be creatable');
        self::assertTrue(mkdir($root . '/tests/Support', 0777, true), 'the tests/Support directory must be creatable');
        $this->projectRoot = $root;

        // A real class the workspace declares in tests/Support/. Its short
        // name shares the "str" prefix so it competes with the built-in
        // `string` at type-hint completion time.
        file_put_contents(
            $root . '/tests/Support/Streamer.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Overlap\\Tests\\Support;\n\nclass Streamer\n{\n}\n",
        );

        // The overlapping PSR-4 layout: the base prefix names two roots, and
        // the sub-prefix names one of those roots on its own. Under this
        // configuration a file at tests/Support/Streamer.php confirms as
        // `Overlap\Tests\Support\Streamer` through the sub-prefix *and* as
        // `Overlap\Support\Streamer` through the base prefix — the second
        // name is a false positive.
        $map = new ComposerAutoloadMap(psr4: [
            'Overlap\\Tests\\' => [$root . '/tests'],
            'Overlap\\' => [$root . '/src', $root . '/tests'],
        ]);

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
     * The false-positive candidate FQN — `Overlap\Support\Streamer` — names
     * no class inside `tests/Support/Streamer.php`; only
     * `Overlap\Tests\Support\Streamer` does. A completion that offered the
     * false name would misdirect the user, and worse, callers acting on it
     * (a workspace grep, an autoload probe) reach an unresolvable name.
     */
    public function testFalsePositiveOverlapNameIsNotOffered(): void
    {
        $labels = $this->completeAt(
            "<?php\nfunction f(Str",
            line: 1,
            character: 15,
        );

        self::assertNotContains(
            'Overlap\\Support\\Streamer',
            $labels,
            'the base-prefix candidate names no class in the file; a false positive would '
            . 'reach the user\'s completion menu as an unresolvable name and drive '
            . 'class_exists into autoloading the same file under the wrong FQN',
        );
    }

    /**
     * A parameter-type position with the "str" prefix: the built-in `string`
     * is the user's most likely target and must be in the response, ahead of
     * any workspace class the prefix also matches. This is the minimum
     * observable behaviour: no matter what the workspace autoload declares,
     * a type-hint position offers PHP's built-in types.
     */
    public function testBuiltinStringAtParameterTypeHint(): void
    {
        $labels = $this->completeAt(
            "<?php\nfunction f(str",
            line: 1,
            character: 15,
        );

        self::assertContains(
            'string',
            $labels,
            'string must be offered at a parameter type-hint position, whatever else the '
            . 'workspace autoload names at the same prefix',
        );
    }

    /**
     * A return-type position: the same guarantee at the return-type slot,
     * which the classifier reaches through a different pattern.
     */
    public function testBuiltinStringAtReturnTypeHint(): void
    {
        $labels = $this->completeAt(
            "<?php\nfunction f(): str",
            line: 1,
            character: 17,
        );

        self::assertContains(
            'string',
            $labels,
            'string must be offered at a return type-hint position, whatever else the '
            . 'workspace autoload names at the same prefix',
        );
    }

    /**
     * A property-type position (after `public`): the same guarantee at the
     * property-type slot, which the classifier reaches through
     * `AfterVisibility`.
     */
    public function testBuiltinStringAtPropertyTypeHint(): void
    {
        $labels = $this->completeAt(
            "<?php\nclass X { public str",
            line: 1,
            character: 25,
        );

        self::assertContains(
            'string',
            $labels,
            'string must be offered at a property type-hint position, whatever else the '
            . 'workspace autoload names at the same prefix',
        );
    }

    /**
     * At an empty parameter-type position — no prefix typed yet — every
     * built-in scalar type must be present. If the response is truncated at
     * this position, every one of these is a name the user is most likely to
     * pick, so none belong past the cap.
     */
    public function testAllCommonBuiltinsAtEmptyParameterTypeHint(): void
    {
        $labels = $this->completeAt(
            "<?php\nfunction f(",
            line: 1,
            character: 12,
        );

        $builtins = ['string', 'int', 'float', 'bool', 'array', 'object', 'mixed', 'iterable', 'callable', 'null'];
        foreach ($builtins as $builtin) {
            self::assertContains(
                $builtin,
                $labels,
                "the built-in {$builtin} must be offered at an empty parameter type-hint position",
            );
        }
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
