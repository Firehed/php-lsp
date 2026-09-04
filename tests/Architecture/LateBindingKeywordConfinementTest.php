<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * The three late-binding keywords `self`, `static`, and `parent` name themselves
 * in exactly one place, {@see \Firehed\PhpLsp\Domain\LateBindingKeyword}. Every
 * other file in `src/` identifies one through
 * {@see \Firehed\PhpLsp\Domain\LateBindingKeyword::tryFromName()} and resolves
 * it through {@see \Firehed\PhpLsp\Domain\LateBindingKeyword::resolveIn()}, so
 * no other file compares a string against the three literals. A future direct
 * comparison would fork the parent-of-non-`Class_` guard and the case-folding
 * rule apart from the enum's method; this test fails when one appears.
 */
final class LateBindingKeywordConfinementTest extends TestCase
{
    use ScansSourceFiles;

    /**
     * The one file allowed to name the three keyword literals as their own
     * strings; removing this loosens (human only) — every other file must
     * route through the enum's methods.
     */
    private const string ALLOWED_FILE = 'src/Domain/LateBindingKeyword.php';

    public const array KEYWORDS = ['self', 'static', 'parent'];

    public function testNoSrcFileComparesAgainstKeywordLiterals(): void
    {
        $violations = [];
        foreach (self::sourceFiles() as $file) {
            $relative = self::relativePath($file);
            if ($relative === self::ALLOWED_FILE) {
                continue;
            }
            foreach (self::comparisonsAgainstKeywords($file) as $line => $keyword) {
                $violations[] = "{$relative}:{$line} compares against '{$keyword}'";
            }
        }

        self::assertSame(
            [],
            $violations,
            'compare through LateBindingKeyword::tryFromName() instead of a bare string; '
                . 'the parent-of-non-Class guard lives on resolveIn()',
        );
    }

    /**
     * A rule that reports nothing is indistinguishable from a rule that scans
     * for nothing. This canary drives every comparison form the scanner cares
     * about so a regression in the visitor cannot pass unnoticed.
     */
    public function testScannerCatchesEveryComparisonForm(): void
    {
        $canary = self::root() . '/tests/Architecture/data/compares-late-binding-keyword.php';

        $keywords = [];
        foreach (self::comparisonsAgainstKeywords($canary) as $keyword) {
            $keywords[] = $keyword;
        }

        sort($keywords);
        self::assertSame(
            ['parent', 'parent', 'parent', 'self', 'self', 'self', 'self', 'static', 'static'],
            $keywords,
            'the scanner must catch identity, equality, and their negations, plus match and switch arms',
        );
    }

    /**
     * Every line in `$file` that compares a string literal against one of the
     * three keywords, mapped to the keyword's value.
     *
     * @return iterable<int, string>
     */
    private static function comparisonsAgainstKeywords(string $file): iterable
    {
        $content = file_get_contents($file);
        self::assertIsString($content, "unable to read {$file}");

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($content) ?? [];

        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<array{int, string}> */
            public array $hits = [];

            public function enterNode(Node $node): ?int
            {
                if ($this->isEquality($node)) {
                    /** @var BinaryOp $node */
                    $this->recordIfKeyword($node->left, $node->getStartLine());
                    $this->recordIfKeyword($node->right, $node->getStartLine());
                    return null;
                }
                if ($node instanceof Match_) {
                    foreach ($node->arms as $arm) {
                        if ($arm->conds === null) {
                            continue;
                        }
                        foreach ($arm->conds as $cond) {
                            $this->recordIfKeyword($cond, $cond->getStartLine());
                        }
                    }
                    return null;
                }
                if ($node instanceof Switch_) {
                    foreach ($node->cases as $case) {
                        if ($case->cond === null) {
                            continue;
                        }
                        $this->recordIfKeyword($case->cond, $case->getStartLine());
                    }
                }
                return null;
            }

            private function isEquality(Node $node): bool
            {
                return $node instanceof BinaryOp\Identical
                    || $node instanceof BinaryOp\NotIdentical
                    || $node instanceof BinaryOp\Equal
                    || $node instanceof BinaryOp\NotEqual;
            }

            private function recordIfKeyword(Expr $expr, int $line): void
            {
                if (!$expr instanceof String_) {
                    return;
                }
                $value = strtolower($expr->value);
                if (in_array($value, LateBindingKeywordConfinementTest::KEYWORDS, true)) {
                    $this->hits[] = [$line, $value];
                }
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        foreach ($visitor->hits as [$line, $keyword]) {
            yield $line => $keyword;
        }
    }
}
