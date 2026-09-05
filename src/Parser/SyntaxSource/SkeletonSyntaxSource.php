<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Const_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\UseItem;

/**
 * The regex-based {@see SyntaxSource}. Reads the structural shape of PHP source
 * — namespace, imports, class-likes with `extends`/`implements`, and their
 * top-level members — from the text alone, so a document php-parser cannot make
 * sense of still yields a tree that {@see \Firehed\PhpLsp\Utility\Scope},
 * {@see \Firehed\PhpLsp\Knowledge\DeclarationScanner}, and
 * {@see \Firehed\PhpLsp\Repository\DefaultClassInfoFactory} read the same way
 * they read a php-parser tree (RFC 1 §5.3, build-manifest step-37).
 *
 * The tree is deliberately minimal: bodies are empty, parameters and types are
 * absent. Only the structural shape a positional query needs — enough for
 * enclosing-class and name-context questions and for member enumeration on
 * `$this->` — is reconstructed.
 *
 * @phpstan-type ClassLikeMatch array{
 *   start: int,
 *   kind: string,
 *   name: string,
 *   nameStart: int,
 *   extends: ?string,
 *   implements: list<string>,
 *   body: string,
 *   bodyStart: int,
 *   end: int,
 * }
 */
final class SkeletonSyntaxSource implements SyntaxSource
{
    private const string NAME_PATTERN = '[A-Za-z_\\\\][A-Za-z0-9_\\\\]*';
    private const string SIMPLE_NAME_PATTERN = '[A-Za-z_][A-Za-z0-9_]*';

    public function __construct(
        private readonly TreeAnnotator $annotator,
    ) {
    }

    /**
     * @return array<Stmt>
     */
    public function parse(TextDocument $document): array
    {
        $content = $document->getContent();
        $tree = $this->buildTree($content);
        if ($tree === []) {
            return [];
        }
        return $this->annotator->annotate($tree);
    }

    /**
     * @return array<Stmt>
     */
    private function buildTree(string $content): array
    {
        $namespaces = $this->findNamespaces($content);
        $classes = $this->findClassLikes($content);

        if ($namespaces === [] && $classes === []) {
            return [];
        }

        $globalUses = $this->buildUseStmts($content, 0, strlen($content), 0);

        if ($namespaces === []) {
            return [...$globalUses, ...$this->classNodes($classes, $content)];
        }

        $stmts = [];
        foreach ($namespaces as $i => $ns) {
            $end = $namespaces[$i + 1]['start'] ?? strlen($content);
            $bodyStart = $ns['bodyStart'];
            $isBraced = $ns['kind'] === Stmt\Namespace_::KIND_BRACED;
            $bodyEnd = $isBraced
                ? $this->findMatchingBrace($content, $bodyStart - 1)
                : $end;

            $baseDepth = $isBraced ? $this->braceDepthAt($content, $bodyStart) : 0;
            $uses = $this->buildUseStmts($content, $bodyStart, $bodyEnd, $baseDepth);
            $nsClasses = array_values(array_filter(
                $classes,
                static fn (array $c): bool => $c['start'] >= $bodyStart && $c['start'] < $bodyEnd,
            ));
            $nsBody = [...$uses, ...$this->classNodes($nsClasses, $content)];

            $nameNode = $ns['name'] === ''
                ? null
                : new Name($ns['name'], self::positions($ns['nameStart'], $ns['nameStart'] + strlen($ns['name'])));

            $stmts[] = new Stmt\Namespace_(
                $nameNode,
                $nsBody,
                [
                    ...self::positions($ns['start'], $bodyEnd),
                    'kind' => $ns['kind'],
                ],
            );
        }

        return $stmts;
    }

    /**
     * @return list<array{start: int, end: int, name: string, nameStart: int, kind: int, bodyStart: int}>
     */
    private function findNamespaces(string $content): array
    {
        // Anchor at line start so a "namespace" token inside a docblock does
        // not read as a declaration.
        $pattern = '/^\s*namespace(?:\s+(' . self::NAME_PATTERN . '))?\s*([;{])/m';
        $matches = [];
        if (
            preg_match_all(
                $pattern,
                $content,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            ) === false
        ) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('preg_match_all with valid pattern cannot fail');
            // @codeCoverageIgnoreEnd
        }

        $out = [];
        foreach ($matches as $m) {
            $start = $m[0][1];
            // With PREG_OFFSET_CAPTURE, an unmatched optional group returns
            // ["", -1]. An empty-name namespace declaration is invalid PHP;
            // the anchor position is only used as a fallback.
            $name = $m[1][0];
            $nameStart = $m[1][1] === -1 ? $start : $m[1][1];
            $kind = $m[2][0] === '{' ? Stmt\Namespace_::KIND_BRACED : Stmt\Namespace_::KIND_SEMICOLON;
            $bodyStart = $m[2][1] + 1;
            $out[] = [
                'start' => $start,
                'end' => $start,
                'name' => $name,
                'nameStart' => $nameStart,
                'kind' => $kind,
                'bodyStart' => $bodyStart,
            ];
        }
        return $out;
    }

    /**
     * @return list<ClassLikeMatch>
     */
    private function findClassLikes(string $content): array
    {
        // Anchor at the start of a line so a "class" or "interface" word that
        // appears inside a docblock or a string does not read as a declaration.
        $pattern = '/^\s*(?:(?:abstract|final|readonly)\s+)*(class|interface|trait|enum)\s+(\w+)'
            . '(?:\s+extends\s+((?:' . self::NAME_PATTERN . ')(?:\s*,\s*' . self::NAME_PATTERN . ')*))?'
            . '(?:\s+implements\s+(' . self::NAME_PATTERN . '(?:\s*,\s*' . self::NAME_PATTERN . ')*))?/m';

        $matches = [];
        if (
            preg_match_all(
                $pattern,
                $content,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            ) === false
        ) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('preg_match_all with valid pattern cannot fail');
            // @codeCoverageIgnoreEnd
        }

        $out = [];
        foreach ($matches as $m) {
            $start = $m[0][1];
            $extends = $m[3][0] ?? '';
            $implements = $m[4][0] ?? '';
            $body = $this->sliceClassBody($content, $start);
            $out[] = [
                'start' => $start,
                'kind' => $m[1][0],
                'name' => $m[2][0],
                'nameStart' => $m[2][1],
                'extends' => $extends === '' ? null : $extends,
                'implements' => $implements === ''
                    ? []
                    : array_map(trim(...), explode(',', $implements)),
                'body' => $body,
                'bodyStart' => $start,
                'end' => $start + strlen($body),
            ];
        }
        return $out;
    }

    /**
     * @param list<ClassLikeMatch> $classes
     * @return list<Stmt\ClassLike>
     */
    private function classNodes(array $classes, string $content): array
    {
        $out = [];
        foreach ($classes as $c) {
            $out[] = $this->buildClassLike($c, $content);
        }
        return $out;
    }

    /**
     * @param ClassLikeMatch $c
     */
    private function buildClassLike(array $c, string $content): Stmt\ClassLike
    {
        $members = [
            ...$this->buildMethods($c['body'], $c['start']),
            ...$this->buildProperties($c['body'], $c['start']),
            ...$this->buildConstants($c['body'], $c['start']),
        ];
        self::extendMemberSpans($members, $c['end']);
        $nameNode = new Identifier(
            $c['name'],
            self::positions($c['nameStart'], $c['nameStart'] + strlen($c['name'])),
        );
        $attributes = self::positions($c['start'], $c['end']);

        return match ($c['kind']) {
            'interface' => new Stmt\Interface_(
                $nameNode,
                [
                    'extends' => $c['extends'] === null
                        ? []
                        : [self::name($c['extends'], $c['start'])],
                    'stmts' => $members,
                ],
                $attributes,
            ),
            'trait' => new Stmt\Trait_(
                $nameNode,
                ['stmts' => $members],
                $attributes,
            ),
            'enum' => new Stmt\Enum_(
                $nameNode,
                [
                    'scalarType' => null,
                    'implements' => array_map(fn (string $n): Name => self::name($n, $c['start']), $c['implements']),
                    'stmts' => $members,
                ],
                $attributes,
            ),
            default => new Stmt\Class_(
                $nameNode,
                [
                    'flags' => 0,
                    'extends' => $c['extends'] === null ? null : self::name($c['extends'], $c['start']),
                    'implements' => array_map(fn (string $n): Name => self::name($n, $c['start']), $c['implements']),
                    'stmts' => $members,
                ],
                $attributes,
            ),
        };
    }

    /**
     * @return list<Stmt\ClassMethod>
     */
    private function buildMethods(string $body, int $baseOffset): array
    {
        $pattern = '/^\s*(public|protected|private)\s+(static\s+)?function\s+(\w+)\s*\(/m';
        $matches = [];
        if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('preg_match_all with valid pattern cannot fail');
            // @codeCoverageIgnoreEnd
        }
        $out = [];
        foreach ($matches as $m) {
            $flags = self::visibilityFlag($m[1][0])
                | ($m[2][0] !== '' ? Modifiers::STATIC : 0);
            $matchStart = $baseOffset + $m[0][1];
            $matchEnd = $matchStart + strlen($m[0][0]);
            $nameStart = $baseOffset + $m[3][1];
            $out[] = new Stmt\ClassMethod(
                new Identifier($m[3][0], self::positions($nameStart, $nameStart + strlen($m[3][0]))),
                [
                    'flags' => $flags,
                    'params' => [],
                    'returnType' => null,
                    'stmts' => [],
                ],
                self::positions($matchStart, $matchEnd),
            );
        }
        return $out;
    }

    /**
     * @return list<Stmt\Property>
     */
    private function buildProperties(string $body, int $baseOffset): array
    {
        $pattern = '/^\s*(public|protected|private)\s+(static\s+)?(readonly\s+)?(?:[\w\\\\|?]+\s+)?\$(\w+)/m';
        $matches = [];
        if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('preg_match_all with valid pattern cannot fail');
            // @codeCoverageIgnoreEnd
        }
        $out = [];
        foreach ($matches as $m) {
            $flags = self::visibilityFlag($m[1][0])
                | ($m[2][0] !== '' ? Modifiers::STATIC : 0)
                | ($m[3][0] !== '' ? Modifiers::READONLY : 0);
            $matchStart = $baseOffset + $m[0][1];
            $matchEnd = $matchStart + strlen($m[0][0]);
            $out[] = new Stmt\Property(
                $flags,
                [new Node\PropertyItem($m[4][0])],
                self::positions($matchStart, $matchEnd),
            );
        }
        return $out;
    }

    /**
     * @return list<Stmt\ClassConst>
     */
    private function buildConstants(string $body, int $baseOffset): array
    {
        $pattern = '/^\s*(public|protected|private)?\s*const\s+(?:[\w\\\\|?]+\s+)?(\w+)\s*=/m';
        $matches = [];
        if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('preg_match_all with valid pattern cannot fail');
            // @codeCoverageIgnoreEnd
        }
        $out = [];
        foreach ($matches as $m) {
            $visibility = $m[1][0] === '' ? 'public' : $m[1][0];
            $flags = self::visibilityFlag($visibility);
            $matchStart = $baseOffset + $m[0][1];
            $matchEnd = $matchStart + strlen($m[0][0]);
            $out[] = new Stmt\ClassConst(
                [new Const_($m[2][0], new Node\Scalar\String_(''))],
                $flags,
                self::positions($matchStart, $matchEnd),
            );
        }
        return $out;
    }

    /**
     * @return list<Stmt>
     */
    private function buildUseStmts(string $content, int $rangeStart, int $rangeEnd, int $baseDepth): array
    {
        $slice = substr($content, $rangeStart, $rangeEnd - $rangeStart);
        // Anchor at line start so a "use" that appears in a docblock or string
        // is not read as an import.
        $pattern = '/^\s*use\s+((?:function|const)\s+)?(' . self::NAME_PATTERN . ')'
            . '(?:\s+as\s+(' . self::SIMPLE_NAME_PATTERN . '))?\s*;'
            . '|^\s*use\s+((?:function|const)\s+)?(' . self::NAME_PATTERN . ')\s*\\\\?\s*\{([^}]+)\}\s*;/m';
        $matches = [];
        if (preg_match_all($pattern, $slice, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('preg_match_all with valid pattern cannot fail');
            // @codeCoverageIgnoreEnd
        }

        $out = [];
        foreach ($matches as $m) {
            $matchStart = $rangeStart + $m[0][1];
            $matchEnd = $matchStart + strlen($m[0][0]);

            // A trait `use` inside a class body sits at a deeper brace depth than
            // an import at the namespace scope; only same-depth `use`s are imports.
            if ($this->braceDepthAt($content, $matchStart) !== $baseDepth) {
                continue;
            }

            // Groups 4-6 belong to the group-use alternative; when one is set,
            // the other alternative did not match, so groups 1-3 are absent.
            if (isset($m[5])) {
                $out[] = $this->buildGroupUse($m, $matchStart, $matchEnd);
                continue;
            }

            if (!isset($m[2])) {
                // @codeCoverageIgnoreStart
                // Exactly one alternative of the regex matches; the group-use
                // branch was rejected above, so group 2 of the simple branch
                // must be present.
                continue;
                // @codeCoverageIgnoreEnd
            }
            $type = self::useType($m[1][0] ?? '');
            $fqn = $m[2][0];
            $alias = ($m[3][0] ?? '') === '' ? null : $m[3][0];
            $useItem = new UseItem(
                new Name($fqn),
                $alias === null ? null : new Identifier($alias),
            );
            $out[] = new Stmt\Use_([$useItem], $type, self::positions($matchStart, $matchEnd));
        }
        return $out;
    }

    /**
     * @param array<int, array{0: string, 1: int}> $m
     */
    private function buildGroupUse(array $m, int $matchStart, int $matchEnd): Stmt\GroupUse
    {
        $type = self::useType($m[4][0] ?? '');
        $prefix = rtrim($m[5][0], '\\');
        $itemsText = $m[6][0];
        $items = [];
        foreach (explode(',', $itemsText) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $aliasPattern = '/^(' . self::NAME_PATTERN . ')\s+as\s+(' . self::SIMPLE_NAME_PATTERN . ')$/';
            if (preg_match($aliasPattern, $item, $im) === 1) {
                $items[] = new UseItem(new Name($im[1]), new Identifier($im[2]));
            } else {
                $items[] = new UseItem(new Name($item), null);
            }
        }
        return new Stmt\GroupUse(new Name($prefix), $items, $type, self::positions($matchStart, $matchEnd));
    }

    /**
     * The regexes pin each member to its declaration line; a method's body ends
     * only at the next member (or the class body's end). Stretching the span
     * to that boundary is what makes {@see \Firehed\PhpLsp\Utility\Scope::atOffset}
     * find the enclosing method for a cursor sitting in the body — including a
     * body php-parser could not close.
     *
     * @param list<Stmt> $members
     */
    private static function extendMemberSpans(array $members, int $classEnd): void
    {
        $sorted = $members;
        usort($sorted, static fn (Stmt $a, Stmt $b): int => $a->getStartFilePos() - $b->getStartFilePos());
        $lastIndex = count($sorted) - 1;
        foreach ($sorted as $i => $member) {
            $next = $i === $lastIndex ? $classEnd : $sorted[$i + 1]->getStartFilePos();
            $member->setAttribute('endFilePos', max($member->getEndFilePos(), $next - 1));
        }
    }

    private function braceDepthAt(string $content, int $offset): int
    {
        $depth = 0;
        for ($i = 0; $i < $offset; $i++) {
            $c = $content[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
            }
        }
        return $depth;
    }

    private function findMatchingBrace(string $content, int $braceOffset): int
    {
        $depth = 0;
        $length = strlen($content);
        for ($i = $braceOffset; $i < $length; $i++) {
            $c = $content[$i];
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}' && --$depth === 0) {
                return $i + 1;
            }
        }
        return $length;
    }

    private function sliceClassBody(string $content, int $declOffset): string
    {
        $bracePos = strpos($content, '{', $declOffset);
        if ($bracePos !== false) {
            $depth = 0;
            for ($i = $bracePos, $length = strlen($content); $i < $length; $i++) {
                $c = $content[$i];
                if ($c === '{') {
                    $depth++;
                } elseif ($c === '}' && --$depth === 0) {
                    return substr($content, $declOffset, $i - $declOffset + 1);
                }
            }
        }
        return substr($content, $declOffset);
    }

    private static function name(string $short, int $anchor): Name
    {
        return new Name(ltrim($short, '\\'), self::positions($anchor, $anchor));
    }

    /**
     * @return array{startFilePos: int, endFilePos: int, startLine: int, endLine: int}
     */
    private static function positions(int $start, int $end): array
    {
        // Lines are not derivable from an offset in isolation without the full
        // content; NodeAtPosition and Scope::atOffset read startFilePos/endFilePos
        // for span containment, which is what the skeleton needs. Lines exist so
        // php-parser accessors that read them do not crash.
        return [
            'startFilePos' => $start,
            'endFilePos' => max($start, $end - 1),
            'startLine' => 1,
            'endLine' => 1,
        ];
    }

    private static function visibilityFlag(string $keyword): int
    {
        return match ($keyword) {
            'private' => Modifiers::PRIVATE,
            'protected' => Modifiers::PROTECTED,
            default => Modifiers::PUBLIC,
        };
    }

    /**
     * @return Stmt\Use_::TYPE_NORMAL|Stmt\Use_::TYPE_FUNCTION|Stmt\Use_::TYPE_CONSTANT
     */
    private static function useType(string $keyword): int
    {
        return match (trim($keyword)) {
            'function' => Stmt\Use_::TYPE_FUNCTION,
            'const' => Stmt\Use_::TYPE_CONSTANT,
            default => Stmt\Use_::TYPE_NORMAL,
        };
    }
}
