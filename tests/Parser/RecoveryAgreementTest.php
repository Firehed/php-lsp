<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\NodeAtPosition;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Code being edited must have the structure it will have once completed.
 * Each `//hover:` marker in a fixture that parses becomes three edits in
 * progress — cut off at the end of the marked word, cut off where it starts,
 * and its line cut short with the rest of the file kept — and each must agree
 * with the complete file at that position.
 *
 * Cases that do not agree yet are pinned in a golden file with what they read
 * as today, so a fix and a regression both show up as a change to it.
 * Recapture with UPDATE_GOLDENS=1.
 *
 * @phpstan-type Description array<string, ?string>
 */
#[CoversNothing]
final class RecoveryAgreementTest extends TestCase
{
    use LoadsFixturesTrait;

    private const string GOLDEN_PATH = __DIR__ . '/goldens/recovery-disagreements.json';

    public function testEditsInProgressAgreeWithTheCompletedCode(): void
    {
        $source = ProductionSyntaxSource::unmemoized();
        $disagreements = [];
        $cases = 0;
        foreach (self::completeFixtures($source) as $fixture => $content) {
            preg_match_all('/\/\/hover:(\w+)/', $content, $markers);
            foreach (array_unique($markers[1]) as $marker) {
                $start = $this->symbolStart($content, $marker);
                if ($start === null) {
                    continue;
                }
                preg_match('/\G\$?[\w\\\\]+/', $content, $word, 0, $start);
                $end = $start + strlen($word[0] ?? '');
                $lineEnd = strpos($content, "\n", $end);
                $edits = [
                    'word cut short' => [substr($content, 0, $end), $end - 1, false],
                    'word not typed' => [substr($content, 0, $start), max(0, $start - 1), true],
                    'line cut short' => [
                        substr($content, 0, $end) . ($lineEnd === false ? '' : substr($content, $lineEnd)),
                        $end - 1,
                        false,
                    ],
                ];
                foreach ($edits as $edit => [$text, $offset, $slotOnly]) {
                    $cases++;
                    $want = self::describe(self::tree($source, $content), $offset, $slotOnly);
                    $got = self::describe(self::tree($source, $text), $offset, $slotOnly);
                    if ($got !== $want) {
                        $disagreements["{$fixture}#{$marker} ({$edit})"] = $got;
                    }
                }
            }
        }
        self::assertGreaterThan(0, $cases, 'the fixtures must hold markers to derive edits from');
        ksort($disagreements);
        $encoded = json_encode($disagreements, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        if (getenv('UPDATE_GOLDENS') === '1') {
            file_put_contents(self::GOLDEN_PATH, $encoded);
        }
        self::assertFileExists(self::GOLDEN_PATH, 'The golden must exist; capture with UPDATE_GOLDENS=1.');
        self::assertSame(
            file_get_contents(self::GOLDEN_PATH),
            $encoded,
            'Every edit in progress that disagrees with its completed code is pinned with what it reads as today',
        );
    }

    /**
     * @return iterable<string, string>
     */
    private static function completeFixtures(SyntaxSourceInterface $source): iterable
    {
        $root = __DIR__ . '/../Fixtures/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . 'src'));
        $paths = [];
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $paths[] = substr($file->getPathname(), strlen($root));
            }
        }
        sort($paths);
        foreach ($paths as $path) {
            $content = (string) file_get_contents($root . $path);
            $errors = new \PhpParser\ErrorHandler\Collecting();
            (new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse($content, $errors);
            if (!$errors->hasErrors()) {
                yield $path => $content;
            }
        }
    }

    /**
     * Where the marked word starts, or null on a marker line that holds no
     * word the marker helper recognizes.
     */
    private function symbolStart(string $content, string $marker): ?int
    {
        try {
            $position = $this->locateHoverMarker($content, $marker);
        } catch (\AssertionError) {
            return null;
        }
        $lines = explode("\n", $content);
        $offset = 0;
        for ($i = 0; $i < $position['line']; $i++) {
            $offset += strlen($lines[$i]) + 1;
        }
        return $offset + $position['character'];
    }

    /**
     * @return array<Stmt>
     */
    private static function tree(SyntaxSourceInterface $source, string $text): array
    {
        return $source->parse(new TextDocument('file:///edit.php', 'php', 1, $text))->tree;
    }

    /**
     * What encloses the offset and what is written there; where nothing is
     * written yet, what kind of slot it is instead.
     *
     * @param array<Stmt> $tree
     * @return Description
     */
    private static function describe(array $tree, int $offset, bool $slotOnly): array
    {
        $node = (new NodeAtPosition())->find($tree, $offset);
        $class = null;
        $function = null;
        for ($n = $node; $n instanceof Node; $n = $n->getAttribute('parent')) {
            if ($function === null && $n instanceof FunctionLike) {
                $function = $n instanceof Stmt\ClassMethod || $n instanceof Stmt\Function_
                    ? $n->name->toString()
                    : $n::class;
            }
            if ($class === null && $n instanceof Stmt\ClassLike) {
                $class = $n->name?->toString();
            }
        }
        if ($slotOnly) {
            return ['class' => $class, 'function' => $function, 'slot' => self::slot($node)];
        }
        return [
            'class' => $class,
            'function' => $function,
            'node' => $node === null ? null : $node::class,
            'text' => match (true) {
                $node instanceof Node\Identifier, $node instanceof Node\Name => $node->toString(),
                $node instanceof Expr\Variable && is_string($node->name) => '$' . $node->name,
                default => null,
            },
        ];
    }

    private static function slot(?Node $node): ?string
    {
        foreach ([$node, $node?->getAttribute('parent')] as $candidate) {
            $slot = match (true) {
                $candidate instanceof Expr\Variable,
                $candidate instanceof Expr\FuncCall,
                $candidate instanceof Expr\ConstFetch => 'expression',
                $candidate instanceof Expr\MethodCall,
                $candidate instanceof Expr\PropertyFetch,
                $candidate instanceof Expr\NullsafeMethodCall,
                $candidate instanceof Expr\NullsafePropertyFetch => 'member',
                $candidate instanceof Expr\StaticCall,
                $candidate instanceof Expr\StaticPropertyFetch,
                $candidate instanceof Expr\ClassConstFetch => 'static member',
                $candidate instanceof Expr\New_ => 'new',
                $candidate instanceof Node\Attribute => 'attribute',
                default => null,
            };
            if ($slot !== null) {
                return $slot;
            }
        }
        return $node === null ? null : $node::class;
    }
}
