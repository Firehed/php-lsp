<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\BuildsWrittenNamesTrait;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Const_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\UseItem;

/**
 * The regex-based {@see SyntaxSourceInterface}. Reads the structural shape of PHP source
 * — namespace, imports, class-likes with `extends`/`implements`, and their
 * top-level members — from the text alone, so a document php-parser cannot make
 * sense of still yields a tree that {@see \Firehed\PhpLsp\Resolution\Scope},
 * {@see \Firehed\PhpLsp\Knowledge\DeclarationScanner}, and
 * {@see \Firehed\PhpLsp\Repository\DefaultClassInfoFactory} read the same way
 * they read a php-parser tree (RFC 1 §5.3).
 *
 * The tree is deliberately minimal: bodies are empty, parameters and types are
 * absent. Only the structural shape a positional query needs — enough for
 * enclosing-class and name-context questions and for member enumeration on
 * `$this->` — is reconstructed.
 *
 * @phpstan-type NamespaceMatch array{
 *   start: int,
 *   name: string,
 *   nameStart: int,
 *   kind: int,
 *   bodyStart: int,
 * }
 * @phpstan-type WrittenName array{
 *   name: string,
 *   start: int,
 * }
 * @phpstan-type ClassLikeMatch array{
 *   start: int,
 *   kind: string,
 *   name: string,
 *   nameStart: int,
 *   extends: list<WrittenName>,
 *   implements: list<WrittenName>,
 *   body: string,
 *   end: int,
 * }
 * @phpstan-type PositionMap array{
 *   lineStarts: list<int>,
 *   bracePositions: list<int>,
 *   braceDepths: list<int>,
 * }
 * @phpstan-type Positions array{
 *   startFilePos: int,
 *   endFilePos: int,
 *   startLine: int,
 *   endLine: int,
 * }
 */
final class SkeletonSyntaxSource implements SyntaxSourceInterface
{
    use BuildsWrittenNamesTrait;

    private const string NAME_PATTERN = '[A-Za-z_\\\\][A-Za-z0-9_\\\\]*';
    private const string SIMPLE_NAME_PATTERN = '[A-Za-z_][A-Za-z0-9_]*';
    private const string GROUP_USE_ITEM_ALIAS_PATTERN
        = '/^(' . self::NAME_PATTERN . ')\s+as\s+(' . self::SIMPLE_NAME_PATTERN . ')$/';

    private readonly TreeAnnotator $annotator;

    public function __construct()
    {
        // A regex-recovered tree can carry duplicate `use` aliases or names the
        // file does not resolve; the tolerant annotator swallows those failures
        // so the tree still reaches downstream readers.
        $this->annotator = new TreeAnnotator(tolerant: true);
    }

    public function parse(TextDocument $document): ParsedDocument
    {
        $content = $document->getContent();
        $positions = self::indexContent($content);
        $tree = $this->buildTree($content, $positions);
        if ($tree === []) {
            return new ParsedDocument($document, []);
        }
        return new ParsedDocument($document, $this->annotator->annotate($tree, []));
    }

    /**
     * @param PositionMap $positions
     * @return array<Stmt>
     */
    private function buildTree(string $content, array $positions): array
    {
        $namespaces = self::findNamespaces($content);
        $classes = self::findClassLikes($content);

        if ($namespaces === [] && $classes === []) {
            return [];
        }

        if ($namespaces === []) {
            $globalUses = $this->buildUseStmts($content, $positions, 0, strlen($content), 0);
            return [...$globalUses, ...$this->buildClassLikes($classes, $positions)];
        }

        // Classes and namespaces both arrive from preg_match_all in position
        // order, so a single sweep suffices — no per-namespace filter over
        // classes needed.
        $classCursor = 0;
        $classCount = count($classes);
        $stmts = [];
        foreach ($namespaces as $i => $ns) {
            $isBraced = $ns['kind'] === Stmt\Namespace_::KIND_BRACED;
            $bodyEnd = $isBraced
                ? self::findMatchingBrace($content, $ns['bodyStart'] - 1)
                : ($namespaces[$i + 1]['start'] ?? strlen($content));
            $baseDepth = $isBraced ? self::depthAt($positions, $ns['bodyStart']) : 0;

            $nsClasses = [];
            while ($classCursor < $classCount && $classes[$classCursor]['start'] < $bodyEnd) {
                if ($classes[$classCursor]['start'] >= $ns['bodyStart']) {
                    $nsClasses[] = $classes[$classCursor];
                }
                $classCursor++;
            }

            $uses = $this->buildUseStmts($content, $positions, $ns['bodyStart'], $bodyEnd, $baseDepth);
            $nsBody = [...$uses, ...$this->buildClassLikes($nsClasses, $positions)];

            $nameNode = $ns['name'] === ''
                ? null
                : new Name(
                    $ns['name'],
                    self::positions($positions, $ns['nameStart'], $ns['nameStart'] + strlen($ns['name'])),
                );

            $stmts[] = new Stmt\Namespace_(
                $nameNode,
                $nsBody,
                [
                    ...self::positions($positions, $ns['start'], $bodyEnd),
                    'kind' => $ns['kind'],
                ],
            );
        }

        return $stmts;
    }

    /**
     * @return list<NamespaceMatch>
     */
    private static function findNamespaces(string $content): array
    {
        // Anchor at line start so a "namespace" token inside a docblock does
        // not read as a declaration.
        $pattern = '/^\s*namespace(?:\s+(' . self::NAME_PATTERN . '))?\s*([;{])/m';
        $matches = self::matchAll($pattern, $content);

        $out = [];
        foreach ($matches as $m) {
            $start = $m[0][1];
            // With PREG_OFFSET_CAPTURE, an unmatched optional group returns
            // ["", -1]. An empty-name namespace declaration is invalid PHP;
            // the anchor position is only used as a fallback.
            $name = $m[1][0];
            $nameStart = $m[1][1] === -1 ? $start : $m[1][1];
            $kind = $m[2][0] === '{' ? Stmt\Namespace_::KIND_BRACED : Stmt\Namespace_::KIND_SEMICOLON;
            $out[] = [
                'start' => $start,
                'name' => $name,
                'nameStart' => $nameStart,
                'kind' => $kind,
                'bodyStart' => $m[2][1] + 1,
            ];
        }
        return $out;
    }

    /**
     * @return list<ClassLikeMatch>
     */
    private static function findClassLikes(string $content): array
    {
        // Anchor at the start of a line so a "class" or "interface" word that
        // appears inside a docblock or a string does not read as a declaration.
        // `\K` starts the match at the declaration, not on blank lines before it.
        $pattern = '/^\s*\K(?:(?:abstract|final|readonly)\s+)*(class|interface|trait|enum)\s+(\w+)'
            . '(?:\s+extends\s+((?:' . self::NAME_PATTERN . ')(?:\s*,\s*' . self::NAME_PATTERN . ')*))?'
            . '(?:\s+implements\s+(' . self::NAME_PATTERN . '(?:\s*,\s*' . self::NAME_PATTERN . ')*))?/m';
        $matches = self::matchAll($pattern, $content);

        $out = [];
        foreach ($matches as $m) {
            $start = $m[0][1];
            $body = self::sliceClassBody($content, $start, $m[2][1] + strlen($m[2][0]));
            // A trailing optional group that did not participate in the match
            // may be omitted from `$m` (older PHP) rather than returned as
            // ["", -1]; the coalesce covers both.
            $out[] = [
                'start' => $start,
                'kind' => $m[1][0],
                'name' => $m[2][0],
                'nameStart' => $m[2][1],
                'extends' => self::writtenNames($m[3] ?? ['', -1]),
                'implements' => self::writtenNames($m[4] ?? ['', -1]),
                'body' => $body,
                'end' => $start + strlen($body),
            ];
        }
        return $out;
    }

    /**
     * Each name in a captured comma-separated list, at the offset it is written.
     *
     * @param array{0: string, 1: int} $list
     * @return list<WrittenName>
     */
    private static function writtenNames(array $list): array
    {
        $names = [];
        foreach (self::matchAll('/' . self::NAME_PATTERN . '/', $list[0]) as $m) {
            $names[] = ['name' => $m[0][0], 'start' => $list[1] + $m[0][1]];
        }
        return $names;
    }

    /**
     * @param list<ClassLikeMatch> $classes
     * @param PositionMap $positions
     * @return list<Stmt\ClassLike>
     */
    private function buildClassLikes(array $classes, array $positions): array
    {
        return array_map(fn (array $c): Stmt\ClassLike => $this->buildClassLike($c, $positions), $classes);
    }

    /**
     * @param ClassLikeMatch $c
     * @param PositionMap $positions
     */
    private function buildClassLike(array $c, array $positions): Stmt\ClassLike
    {
        $members = [
            ...$this->buildMethods($c['body'], $c['start'], $positions),
            ...$this->buildProperties($c['body'], $c['start'], $positions),
            ...$this->buildConstants($c['body'], $c['start'], $positions),
        ];
        self::extendMemberSpans($members, $c['end']);
        $nameNode = new Identifier(
            $c['name'],
            self::positions($positions, $c['nameStart'], $c['nameStart'] + strlen($c['name'])),
        );
        $attributes = self::positions($positions, $c['start'], $c['end']);

        return match ($c['kind']) {
            'interface' => new Stmt\Interface_(
                $nameNode,
                [
                    'extends' => self::names($c['extends'], $positions),
                    'stmts' => $members,
                ],
                $attributes,
            ),
            'trait' => new Stmt\Trait_($nameNode, ['stmts' => $members], $attributes),
            'enum' => new Stmt\Enum_(
                $nameNode,
                [
                    'scalarType' => null,
                    'implements' => self::names($c['implements'], $positions),
                    'stmts' => $members,
                ],
                $attributes,
            ),
            default => new Stmt\Class_(
                $nameNode,
                [
                    'flags' => 0,
                    'extends' => self::names($c['extends'], $positions)[0] ?? null,
                    'implements' => self::names($c['implements'], $positions),
                    'stmts' => $members,
                ],
                $attributes,
            ),
        };
    }

    /**
     * @param PositionMap $positions
     * @return list<Stmt\ClassMethod>
     */
    private function buildMethods(string $body, int $baseOffset, array $positions): array
    {
        $pattern = '/^\s*(public|protected|private)\s+(static\s+)?function\s+(\w+)\s*\(([^)]*)\)/m';
        $out = [];
        foreach (self::matchAll($pattern, $body) as $m) {
            $flags = self::visibilityFlag($m[1][0])
                | ($m[2][0] !== '' ? Modifiers::STATIC : 0);
            $matchStart = $baseOffset + $m[0][1];
            $matchEnd = $matchStart + strlen($m[0][0]);
            $nameStart = $baseOffset + $m[3][1];
            $paramsText = $m[4][0];
            $paramsAnchor = $baseOffset + $m[4][1];
            $params = self::buildParams($paramsText, $paramsAnchor, $positions);
            $out[] = new Stmt\ClassMethod(
                new Identifier(
                    $m[3][0],
                    self::positions($positions, $nameStart, $nameStart + strlen($m[3][0])),
                ),
                ['flags' => $flags, 'params' => $params, 'returnType' => null, 'stmts' => []],
                self::positions($positions, $matchStart, $matchEnd),
            );
        }
        return $out;
    }

    /**
     * A one-line parameter parser: it reads `type $name` pairs separated by
     * commas, mirroring what {@see \Firehed\PhpLsp\Resolution\ExpressionResolver}
     * needs from the skeleton to type a `$var` receiver in a broken method.
     * Default values, variadics, and nested parens are dropped — the type text
     * is the shape a positional receiver query reads.
     *
     * @param PositionMap $positions
     * @return list<Node\Param>
     */
    private static function buildParams(string $paramsText, int $anchor, array $positions): array
    {
        $out = [];
        // Each element: `?TypeRef\Name $name` or `Type $name = default`.
        $pattern = '/(?:^|,)\s*((?:\?[?\w\\\\|]+)|[\w\\\\|]+)?\s*\$(\w+)/';
        if (preg_match_all($pattern, $paramsText, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            // @codeCoverageIgnoreStart
            return [];
            // @codeCoverageIgnoreEnd
        }
        foreach ($matches as $m) {
            $typeText = $m[1][0];
            $typeOffset = $m[1][1];
            $name = $m[2][0];
            $nameOffsetInParams = $m[2][1];
            $nameStart = $anchor + $nameOffsetInParams;
            $nameEnd = $nameStart + strlen($name);
            $var = new Node\Expr\Variable(
                $name,
                self::positions($positions, $nameStart, $nameEnd),
            );
            $type = self::parseTypeText($typeText, $anchor + $typeOffset, $positions);
            $out[] = new Node\Param(
                $var,
                default: null,
                type: $type,
                attributes: self::positions($positions, $nameStart, $nameEnd),
            );
        }
        return $out;
    }

    /**
     * @param PositionMap $positions
     */
    private static function parseTypeText(
        string $text,
        int $anchor,
        array $positions,
    ): Node\Identifier|Node\Name|Node\NullableType|null {
        $text = trim($text);
        $typeStart = max(0, $anchor);
        $nullable = str_starts_with($text, '?');
        if ($nullable) {
            $text = substr($text, 1);
        }
        $nameStart = $nullable ? $typeStart + 1 : $typeStart;
        // Union types: A|B. Intersection is not attempted; the receiver query
        // reads the first constituent to type the variable, which is enough for
        // completion inside a broken method. `explode` on an empty string still
        // yields one element, so this one check catches every empty-input shape
        // the buildParams regex admits (no annotation, and pathological input
        // that captures only pipes).
        $first = trim(explode('|', $text)[0]);
        if ($first === '') {
            return null;
        }
        $nameEnd = $nameStart + strlen($first);
        $attrs = self::positions($positions, $nameStart, $nameEnd);
        // Primitive type names are compared verbatim: parameter types in PHP
        // source are conventionally lowercase, and the fallback path — treating
        // an unrecognised token as a class name — is safe when the file's
        // actual type resolution rejects it downstream.
        $node = in_array($first, self::PRIMITIVE_TYPES, true)
            ? new Node\Identifier($first, $attrs)
            : self::nameAsWritten($first, $attrs);
        return $nullable
            ? new Node\NullableType($node, self::positions($positions, $typeStart, $nameEnd))
            : $node;
    }

    private const array PRIMITIVE_TYPES = [
        'int', 'float', 'string', 'bool', 'void', 'mixed', 'array',
        'object', 'callable', 'iterable', 'self', 'static', 'never',
        'false', 'true', 'null',
    ];

    /**
     * @param PositionMap $positions
     * @return list<Stmt\Property>
     */
    private function buildProperties(string $body, int $baseOffset, array $positions): array
    {
        $pattern = '/^\s*(public|protected|private)\s+(static\s+)?(readonly\s+)?(?:[\w\\\\|?]+\s+)?\$(\w+)/m';
        $out = [];
        foreach (self::matchAll($pattern, $body) as $m) {
            $flags = self::visibilityFlag($m[1][0])
                | ($m[2][0] !== '' ? Modifiers::STATIC : 0)
                | ($m[3][0] !== '' ? Modifiers::READONLY : 0);
            $matchStart = $baseOffset + $m[0][1];
            $matchEnd = $matchStart + strlen($m[0][0]);
            // The name as written includes the `$` before the captured group.
            $nameAttributes = self::positions($positions, $baseOffset + $m[4][1] - 1, $matchEnd);
            $out[] = new Stmt\Property(
                $flags,
                [new Node\PropertyItem(new Node\VarLikeIdentifier($m[4][0], $nameAttributes), null, $nameAttributes)],
                self::positions($positions, $matchStart, $matchEnd),
            );
        }
        return $out;
    }

    /**
     * @param PositionMap $positions
     * @return list<Stmt\ClassConst>
     */
    private function buildConstants(string $body, int $baseOffset, array $positions): array
    {
        $pattern = '/^\s*(public|protected|private)?\s*const\s+(?:[\w\\\\|?]+\s+)?(\w+)\s*=/m';
        $out = [];
        foreach (self::matchAll($pattern, $body) as $m) {
            $visibility = $m[1][0] === '' ? 'public' : $m[1][0];
            $matchStart = $baseOffset + $m[0][1];
            $matchEnd = $matchStart + strlen($m[0][0]);
            $nameStart = $baseOffset + $m[2][1];
            $name = new Identifier($m[2][0], self::positions($positions, $nameStart, $nameStart + strlen($m[2][0])));
            // The value is not read. Its placeholder ends before it starts, so it
            // carries positions but no offset falls inside it.
            $value = new Node\Scalar\String_('', [
                ...self::positions($positions, $matchEnd, $matchEnd),
                'endFilePos' => $matchEnd - 1,
            ]);
            $out[] = new Stmt\ClassConst(
                [new Const_($name, $value, self::positions($positions, $nameStart, $matchEnd))],
                self::visibilityFlag($visibility),
                self::positions($positions, $matchStart, $matchEnd),
            );
        }
        return $out;
    }

    /**
     * @param PositionMap $positions
     * @return list<Stmt>
     */
    private function buildUseStmts(
        string $content,
        array $positions,
        int $rangeStart,
        int $rangeEnd,
        int $baseDepth,
    ): array {
        $slice = substr($content, $rangeStart, $rangeEnd - $rangeStart);
        // Anchor at line start so a "use" that appears in a docblock or string
        // is not read as an import.
        $pattern = '/^\s*use\s+((?:function|const)\s+)?(' . self::NAME_PATTERN . ')'
            . '(?:\s+as\s+(' . self::SIMPLE_NAME_PATTERN . '))?\s*;'
            . '|^\s*use\s+((?:function|const)\s+)?(' . self::NAME_PATTERN . ')\s*\\\\?\s*\{([^}]+)\}\s*;/m';

        $out = [];
        foreach (self::matchAll($pattern, $slice) as $m) {
            $matchStart = $rangeStart + $m[0][1];
            $matchEnd = $matchStart + strlen($m[0][0]);

            // A trait `use` inside a class body sits at a deeper brace depth than
            // an import at the namespace scope; only same-depth `use`s are imports.
            if (self::depthAt($positions, $matchStart) !== $baseDepth) {
                continue;
            }

            // Groups 4-6 belong to the group-use alternative; when one is set,
            // the other alternative did not match, so groups 1-3 are absent.
            if (isset($m[5])) {
                $out[] = $this->buildGroupUse($m, $positions, $rangeStart, $matchStart, $matchEnd);
                continue;
            }

            $out[] = new Stmt\Use_(
                [self::useItem($m[2], $m[3] ?? ['', -1], $rangeStart, $positions)],
                self::useType($m[1][0] ?? ''),
                self::positions($positions, $matchStart, $matchEnd),
            );
        }
        return $out;
    }

    /**
     * @param array<int, array{0: string, 1: int}> $m Offsets relative to $base.
     * @param PositionMap $positions
     */
    private function buildGroupUse(
        array $m,
        array $positions,
        int $base,
        int $matchStart,
        int $matchEnd,
    ): Stmt\GroupUse {
        // Called only when the group-use alternative matched, so groups 4-6 are
        // present; coalesce their reads because PHPStan cannot follow the caller
        // narrowing.
        $list = $m[6] ?? ['', -1];
        $items = [];
        // Each item starts at its first non-space character, so the empty item
        // a trailing comma leaves is never matched.
        foreach (self::matchAll('/[^,\s][^,]*/', $list[0]) as $piece) {
            $item = rtrim($piece[0][0]);
            $itemStart = $list[1] + $piece[0][1];
            if (preg_match(self::GROUP_USE_ITEM_ALIAS_PATTERN, $item, $im, PREG_OFFSET_CAPTURE) === 1) {
                $items[] = self::useItem(
                    [$im[1][0], $itemStart + $im[1][1]],
                    [$im[2][0], $itemStart + $im[2][1]],
                    $base,
                    $positions,
                );
            } else {
                $items[] = self::useItem([$item, $itemStart], ['', -1], $base, $positions);
            }
        }
        $prefix = $m[5] ?? ['', -1];
        $prefixName = rtrim($prefix[0], '\\');
        $prefixStart = $base + $prefix[1];
        return new Stmt\GroupUse(
            new Name($prefixName, self::positions($positions, $prefixStart, $prefixStart + strlen($prefixName))),
            $items,
            self::useType($m[4][0] ?? ''),
            self::positions($positions, $matchStart, $matchEnd),
        );
    }

    /**
     * An import of $name, renamed to $alias unless the alias is empty, spanning
     * both as written.
     *
     * @param array{0: string, 1: int} $name Offset relative to $base.
     * @param array{0: string, 1: int} $alias Offset relative to $base.
     * @param PositionMap $positions
     */
    private static function useItem(array $name, array $alias, int $base, array $positions): UseItem
    {
        $nameStart = $base + $name[1];
        $itemEnd = $nameStart + strlen($name[0]);
        $nameNode = new Name($name[0], self::positions($positions, $nameStart, $itemEnd));
        $aliasNode = null;
        if ($alias[0] !== '') {
            $aliasStart = $base + $alias[1];
            $itemEnd = $aliasStart + strlen($alias[0]);
            $aliasNode = new Identifier($alias[0], self::positions($positions, $aliasStart, $itemEnd));
        }

        return new UseItem($nameNode, $aliasNode, attributes: self::positions($positions, $nameStart, $itemEnd));
    }

    /**
     * The regexes pin each member to its declaration line; a method's body ends
     * only at the next member (or the class body's end). Stretching the span
     * to that boundary is what makes {@see \Firehed\PhpLsp\Resolution\Scope::atOffset}
     * find the enclosing method for a cursor sitting in the body — including a
     * body php-parser could not close.
     *
     * @param list<Stmt> $members
     */
    private static function extendMemberSpans(array $members, int $classEnd): void
    {
        usort($members, static fn (Stmt $a, Stmt $b): int => $a->getStartFilePos() - $b->getStartFilePos());
        $lastIndex = count($members) - 1;
        foreach ($members as $i => $member) {
            $next = $i === $lastIndex ? $classEnd : $members[$i + 1]->getStartFilePos();
            $member->setAttribute('endFilePos', max($member->getEndFilePos(), $next - 1));
        }
    }

    /**
     * One linear scan over the content builds both maps:
     *   - `lineStarts[i]` is the offset of the first character of line i+1;
     *   - `bracePositions[k]` and `braceDepths[k]` list every `{` or `}` in
     *     order, and the brace depth right AFTER that character.
     *
     * Callers look up a line or a brace depth by binary-searching these arrays.
     * One pass, plus O(log N) per lookup, replaces a per-lookup scan from zero.
     *
     * @return PositionMap
     */
    private static function indexContent(string $content): array
    {
        $lineStarts = [0];
        $bracePositions = [];
        $braceDepths = [];
        $depth = 0;
        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $c = $content[$i];
            if ($c === "\n") {
                $lineStarts[] = $i + 1;
                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
            } else {
                continue;
            }
            $bracePositions[] = $i;
            $braceDepths[] = $depth;
        }
        return [
            'lineStarts' => $lineStarts,
            'bracePositions' => $bracePositions,
            'braceDepths' => $braceDepths,
        ];
    }

    /**
     * The brace depth immediately before `$offset`.
     *
     * @param PositionMap $positions
     */
    private static function depthAt(array $positions, int $offset): int
    {
        $bracePositions = $positions['bracePositions'];
        $lo = 0;
        $hi = count($bracePositions);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($bracePositions[$mid] < $offset) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo === 0 ? 0 : $positions['braceDepths'][$lo - 1];
    }

    /**
     * The one-based line number containing `$offset`.
     *
     * @param PositionMap $positions
     */
    private static function lineAt(array $positions, int $offset): int
    {
        $lineStarts = $positions['lineStarts'];
        $lo = 0;
        $hi = count($lineStarts);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($lineStarts[$mid] <= $offset) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    /**
     * A brace-matched slice starting at `$declOffset`, running to the matching
     * `}` of the next `{`, or to end-of-file when the body has no closing brace.
     * An empty class body (`class C {}`) still returns the declaration through
     * its `}` — the matcher runs from the byte before the brace so the initial
     * increment reaches depth 1 immediately.
     *
     * When the class has no opening brace at all (a truncated declaration such
     * as `class Foo extends Bar` followed by member-looking lines), the next
     * `{` in the file belongs to a member's body and would misplace the class.
     * In that case the slice runs to the next class-like declaration or to
     * end-of-file, so the truncated class still holds its members.
     */
    private static function sliceClassBody(string $content, int $declOffset, int $nameEnd): string
    {
        $bracePos = strpos($content, '{', $declOffset);
        $nextDeclPos = self::nextClassLikeDeclPos($content, $nameEnd);
        if (
            $bracePos !== false
            && $bracePos < $nextDeclPos
            && self::braceOpensClassBody($content, $declOffset, $bracePos)
        ) {
            $end = self::findMatchingBrace($content, $bracePos - 1);
            return substr($content, $declOffset, $end - $declOffset);
        }
        return substr($content, $declOffset, $nextDeclPos - $declOffset);
    }

    /**
     * True when the first `{` after the class declaration is its own body's
     * opener, false when it belongs to a member (a `class Foo` declaration
     * without its own opening brace ended up latching onto a method's body).
     */
    private static function braceOpensClassBody(string $content, int $declOffset, int $bracePos): bool
    {
        $between = substr($content, $declOffset, $bracePos - $declOffset);
        return preg_match(
            '/\n\s*(?:public|protected|private|static|readonly|abstract|final|const|function)\b/',
            $between,
        ) !== 1;
    }

    /**
     * The offset of the next class-like declaration after the one whose name
     * ends at `$nameEnd`, or the file length when none follows. Every line of
     * that declaration up to its name starts before `$nameEnd`, so the scan
     * cannot find it again, even with a modifier on a line of its own.
     */
    private static function nextClassLikeDeclPos(string $content, int $nameEnd): int
    {
        $pattern = '/^\s*\K(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+\w/m';
        if (preg_match($pattern, $content, $next, PREG_OFFSET_CAPTURE, $nameEnd) === 1) {
            return $next[0][1];
        }
        return strlen($content);
    }

    /**
     * The offset one past the `}` that matches the next `{` at or after
     * `$braceOffset + 1`, or `strlen($content)` when the brace is unclosed.
     * The caller is expected to have located that brace, so the loop starts
     * one byte earlier and lets the initial increment do the depth bookkeeping.
     */
    private static function findMatchingBrace(string $content, int $braceOffset): int
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

    /**
     * @param list<WrittenName> $written
     * @param PositionMap $positions
     * @return list<Name>
     */
    private static function names(array $written, array $positions): array
    {
        $names = [];
        foreach ($written as $name) {
            $names[] = self::nameAsWritten(
                $name['name'],
                self::positions($positions, $name['start'], $name['start'] + strlen($name['name'])),
            );
        }
        return $names;
    }

    /**
     * @param PositionMap $positions
     * @return Positions
     */
    private static function positions(array $positions, int $start, int $end): array
    {
        return [
            'startFilePos' => $start,
            'endFilePos' => max($start, $end - 1),
            'startLine' => self::lineAt($positions, $start),
            'endLine' => self::lineAt($positions, max($start, $end - 1)),
        ];
    }

    /**
     * @return list<array<int, array{0: string, 1: int}>>
     */
    private static function matchAll(string $pattern, string $subject): array
    {
        $matches = [];
        if (preg_match_all($pattern, $subject, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('preg_match_all with valid pattern cannot fail');
            // @codeCoverageIgnoreEnd
        }
        /** @var list<array<int, array{0: string, 1: int}>> */
        return $matches;
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
