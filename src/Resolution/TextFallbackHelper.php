<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\NameCase;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Domain\UnionType;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Utility\Scope;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Text-based fallback for code resolution when AST-based detection fails.
 *
 * Handles cases where PHP-Parser drops incomplete code (e.g., `if ($this->|)`).
 * Uses regex patterns to detect member access and extract information.
 *
 * @internal
 */
final class TextFallbackHelper
{
    public function __construct(
        private readonly MemberResolver $memberResolver,
    ) {
    }

    /**
     * Build a NameContext from raw source lines using regex.
     *
     * @param list<string> $lines
     * @param int $line Zero-based
     */
    public static function nameContextFromText(array $lines, int $line): NameContext
    {
        $namespace = self::findNamespaceFromText($lines, $line);
        $imports = self::extractImportsFromText($lines);

        return new NameContext(
            namespace: $namespace,
            classImports: $imports,
        );
    }

    /**
     * @param list<string> $lines
     */
    private static function findNamespaceFromText(array $lines, int $beforeLine): string
    {
        for ($i = min($beforeLine, count($lines) - 1); $i >= 0; $i--) {
            if (preg_match('/^\s*namespace\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*[;{]/', $lines[$i], $m) === 1) {
                return $m[1];
            }
        }
        return '';
    }

    /**
     * @param list<string> $lines
     * @return array<string, string>
     */
    private static function extractImportsFromText(array $lines): array
    {
        $imports = [];

        $classDecl = '/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+/';
        $name = '[A-Za-z_\\\\][A-Za-z0-9_\\\\]*';
        $simpleName = '[A-Za-z_][A-Za-z0-9_]*';

        foreach ($lines as $lineText) {
            if (preg_match($classDecl, $lineText) === 1) {
                break;
            }

            if (preg_match('/^\s*use\s+/', $lineText) !== 1) {
                continue;
            }

            // Group use: use Prefix\{A, B as C, D\E};
            $groupPattern = '/^\s*use\s+(' . $name . ')\s*\\\\?\s*\{(.+)\}\s*;/';
            if (preg_match($groupPattern, $lineText, $m) === 1) {
                $prefix = rtrim($m[1], '\\');
                $items = preg_split('/\s*,\s*/', $m[2]);
                if ($items === false) {
                    // @codeCoverageIgnoreStart
                    throw new \LogicException('preg_split with valid pattern cannot fail');
                    // @codeCoverageIgnoreEnd
                }
                foreach ($items as $item) {
                    $item = trim($item);
                    $aliasPattern = '/^(' . $name . ')\s+as\s+(' . $simpleName . ')$/';
                    if (preg_match($aliasPattern, $item, $im) === 1) {
                        $imports[$im[2]] = $prefix . '\\' . $im[1];
                    } else {
                        $fqn = $prefix . '\\' . $item;
                        $backslashPos = strrpos($item, '\\');
                        $lastPart = $backslashPos === false
                            ? $item
                            : substr($item, $backslashPos + 1);
                        $imports[$lastPart] = $fqn;
                    }
                }
                continue;
            }

            // Simple use with alias: use Foo\Bar as Baz;
            $simpleAliasPattern = '/^\s*use\s+(' . $name . ')\s+as\s+(' . $simpleName . ')\s*;/';
            if (preg_match($simpleAliasPattern, $lineText, $m) === 1) {
                $imports[$m[2]] = $m[1];
                continue;
            }

            // Simple use: use Foo\Bar\ClassName;
            if (preg_match('/^\s*use\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*;/', $lineText, $m) === 1) {
                $fqn = $m[1];
                $pos = strrpos($fqn, '\\');
                $lastPart = $pos === false ? $fqn : substr($fqn, $pos + 1);
                $imports[$lastPart] = $fqn;
            }
        }

        return $imports;
    }

    /**
     * Match the member-access pattern that ends the given text (typically the
     * source line before the cursor). Returns a typed match struct describing
     * the receiver kind, or null if no member-access pattern is at the tail.
     *
     * This is a text primitive: it holds the regex and nothing else. Resolving
     * the match to a {@see MemberAccessContext} is the caller's job.
     *
     * @return array{kind: 'chain', chain: string, prefix: string}
     *      | array{kind: 'instance', var: string, prefix: string}
     *      | array{kind: 'static', class: string, prefix: string}
     *      | null
     */
    public function matchMemberAccessAt(string $textBeforeCursor): ?array
    {
        // Chained instance access: $this->member->prefix or $this?->member->prefix
        if (preg_match('/(\$this(?:\??->[\w]+(?:\([^)]*\))?)+)\??->([\w]*)$/', $textBeforeCursor, $m) === 1) {
            return ['kind' => 'chain', 'chain' => $m[1], 'prefix' => $m[2]];
        }

        // Simple instance access: $var->prefix or $var?->prefix
        if (preg_match('/\$(\w+)(\?)?->([\w]*)$/', $textBeforeCursor, $m) === 1) {
            return ['kind' => 'instance', 'var' => $m[1], 'prefix' => $m[3]];
        }

        // Static access: ClassName::prefix (excluding $var::)
        if (preg_match('/(?<!\$)([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::([\w]*)$/', $textBeforeCursor, $m) === 1) {
            return ['kind' => 'static', 'class' => $m[1], 'prefix' => $m[2]];
        }

        return null;
    }

    /**
     * Split a `$this->foo->bar()` chain into ordered `(name, isMethodCall)`
     * parts. This is a text primitive: the caller walks the parts against
     * a class hierarchy. The `$this->` prefix must be trimmed by the caller.
     *
     * @return list<array{name: string, isMethodCall: bool}>
     */
    public function splitChainParts(string $chainBody): array
    {
        $rawParts = preg_split('/\??->/', $chainBody);
        if ($rawParts === false) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('preg_split with valid pattern cannot fail');
            // @codeCoverageIgnoreEnd
        }

        $parts = [];
        foreach ($rawParts as $part) {
            if ($part === '') {
                continue;
            }
            $isMethodCall = str_contains($part, '(');
            $name = $isMethodCall ? strstr($part, '(', true) : $part;
            if ($name === false || $name === '') {
                // @codeCoverageIgnoreStart
                throw new \LogicException('name extraction cannot fail after non-empty part check');
                // @codeCoverageIgnoreEnd
            }
            $parts[] = ['name' => $name, 'isMethodCall' => $isMethodCall];
        }
        return $parts;
    }

    /**
     * Find enclosing class name by scanning document text.
     *
     * @return class-string|null
     */
    public function findEnclosingClass(TextDocument $document, int $line): ?string
    {
        return $this->findEnclosingClassFromContent($document->getContent(), $line);
    }

    /**
     * Find enclosing class name by scanning content text.
     *
     * @return class-string|null
     */
    public function findEnclosingClassFromContent(string $content, int $line): ?string
    {
        $lines = explode("\n", $content);

        $classPattern = '/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/i';
        for ($i = $line; $i >= 0; $i--) {
            $lineText = $lines[$i] ?? '';
            if (preg_match($classPattern, $lineText, $matches) === 1) {
                $shortName = $matches[1];
                $namespace = $this->findNamespace($lines, $i);
                if ($namespace !== null) {
                    /** @var class-string */
                    return $namespace . '\\' . $shortName;
                }
                /** @var class-string */
                return $shortName;
            }
        }

        // Code outside any class - no enclosing class found
        return null;
    }

    /**
     * Find namespace declaration by scanning lines.
     *
     * @param list<string> $lines
     */
    public function findNamespace(array $lines, int $beforeLine): ?string
    {
        for ($i = $beforeLine - 1; $i >= 0; $i--) {
            $lineText = $lines[$i] ?? '';
            if (preg_match('/^\s*namespace\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*[;{]/', $lineText, $matches) === 1) {
                return $matches[1];
            }
        }
        return null;
    }

    /**
     * Match the type text of a named parameter in the enclosing function-like
     * declaration reached by scanning backwards from the given line. Returns
     * the raw type token (e.g. "?User" or "Foo|Bar"), or null when no match
     * lands. Resolving each token to a {@see Type} is the caller's job.
     *
     * @param list<string> $lines
     */
    public function matchParameterType(array $lines, int $line, string $varName): ?string
    {
        for ($i = $line; $i >= 0; $i--) {
            $lineText = $lines[$i] ?? '';

            if (preg_match('/function\s+\w+\s*\(/', $lineText) === 1) {
                $declaration = $lineText;
                for ($j = $i; $j < min($i + 10, count($lines)); $j++) {
                    if ($j > $i) {
                        $declaration .= ' ' . $lines[$j];
                    }
                    if (str_contains($declaration, ')')) {
                        break;
                    }
                }

                $pattern = '/([?A-Za-z_\\\\][A-Za-z0-9_\\\\|?]*)\s+\$' . preg_quote($varName, '/') . '\b/';
                if (preg_match($pattern, $declaration, $matches) === 1) {
                    return $matches[1];
                }
                return null;
            }
        }
        return null;
    }

    /**
     * Detect a call from text when AST-based detection fails.
     *
     * @param array<Stmt> $ast
     * @return array{
     *   0: FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute,
     *   1: int,
     *   2: list<string>,
     *   3: int,
     * }|null
     */
    public function detectCallFromText(array $ast, int $offset, string $content, int $line): ?array
    {
        $parenPos = self::findUnclosedParen($content, $offset);
        if ($parenPos === null) {
            return null;
        }

        $textBeforeParen = substr($content, 0, $parenPos);

        $callNode = $this->parseCallPattern($textBeforeParen, $ast, $offset, $line, $content);
        if ($callNode === null) {
            return null;
        }

        $argsText = substr($content, $parenPos + 1, $offset - $parenPos - 1);
        [$activeParam, $usedNames, $positionalCount] = self::parseArgsFromText($argsText);

        return [$callNode, $activeParam, $usedNames, $positionalCount];
    }

    private static function findUnclosedParen(string $content, int $offset): ?int
    {
        $depth = 0;
        for ($i = $offset - 1; $i >= 0; $i--) {
            $char = $content[$i];
            if ($char === ')') {
                $depth++;
            } elseif ($char === '(') {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
            } elseif ($char === ';' || $char === '{' || $char === '}') {
                return null;
            }
        }
        return null;
    }

    /**
     * @param array<Stmt> $ast
     * @return FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute|null
     */
    private function parseCallPattern(
        string $textBeforeParen,
        array $ast,
        int $offset,
        int $line,
        string $content,
    ): FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute|null {
        $text = rtrim($textBeforeParen);
        $context = NameContextFactory::fromAst($ast, $line);

        if (preg_match('/#\[\s*(?:[\w\\\\]+\s*,\s*)*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*$/', $text, $m) === 1) {
            return new Attribute(self::resolvedName($m[1], $context));
        }

        if (preg_match('/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::(\w+)\s*$/', $text, $m) === 1) {
            return new StaticCall(self::resolvedName($m[1], $context), new Identifier($m[2]));
        }

        if (preg_match('/\$(\w+)(\?)?->(\w+)\s*$/', $text, $m) === 1) {
            $varName = $m[1];
            $isNullsafe = $m[2] === '?';
            $methodName = $m[3];
            $var = new Variable($varName);
            if ($varName === 'this') {
                $enclosingClass = $this->resolveEnclosingClassName($ast, $offset, $content, $line);
                if ($enclosingClass !== null) {
                    $var->setAttribute('resolvedType', TypeFactory::className($enclosingClass));
                }
            }
            return $isNullsafe
                ? new NullsafeMethodCall($var, new Identifier($methodName))
                : new MethodCall($var, new Identifier($methodName));
        }

        if (preg_match('/\bnew\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*$/', $text, $m) === 1) {
            return new New_(self::resolvedName($m[1], $context));
        }

        if (preg_match('/\b(\w+)\s*$/', $text, $m) === 1) {
            $funcName = $m[1];
            $keywords = ['if', 'while', 'for', 'foreach', 'switch', 'catch', 'array', 'list'];
            if (!in_array(NameCase::Insensitive->normalize($funcName), $keywords, true)) {
                return new FuncCall(new Name($funcName, ['startLine' => $line + 1]));
            }
        }

        return null;
    }

    private static function resolvedName(string $short, NameContext $context): Name
    {
        $name = new Name($short);
        $fqn = $context->candidates($short, NameKind::ClassLike)[0];
        if ($fqn !== $short) {
            $name->setAttribute('resolvedName', new Name\FullyQualified($fqn));
        }
        return $name;
    }

    /**
     * @return array{0: int, 1: list<string>, 2: int}
     */
    private static function parseArgsFromText(string $argsText): array
    {
        $activeParam = 0;
        $usedNames = [];
        $positionalCount = 0;
        $sawNamedArg = false;

        $depth = 0;
        $currentArg = '';

        for ($i = 0; $i < strlen($argsText); $i++) {
            $char = $argsText[$i];

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
                $currentArg .= $char;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
                $currentArg .= $char;
            } elseif ($char === ',' && $depth === 0) {
                self::processArgText($currentArg, $usedNames, $positionalCount, $sawNamedArg);
                $activeParam++;
                $currentArg = '';
            } else {
                $currentArg .= $char;
            }
        }

        if (preg_match('/^(\w+)\s*:/', trim($currentArg), $m) === 1) {
            $usedNames[] = $m[1];
        }

        return [$activeParam, $usedNames, $positionalCount];
    }

    /**
     * @param list<string> $usedNames
     */
    private static function processArgText(
        string $argText,
        array &$usedNames,
        int &$positionalCount,
        bool &$sawNamedArg,
    ): void {
        $argText = trim($argText);
        if ($argText === '') {
            return;
        }

        if (preg_match('/^(\w+)\s*:/', $argText, $m) === 1) {
            $usedNames[] = $m[1];
            $sawNamedArg = true;
        } elseif (!$sawNamedArg) {
            $positionalCount++;
        }
    }

    /**
     * @param array<Stmt> $ast
     * @return ?class-string
     */
    public function resolveEnclosingClassName(
        array $ast,
        int $offset,
        string $content,
        int $line,
    ): ?string {
        $classLike = Scope::atOffset($ast, $offset)->getEnclosingClassLike();
        if ($classLike !== null) {
            return ScopeFinder::getClassLikeName($classLike);
        }
        return $this->findEnclosingClassFromContent($content, $line);
    }

    /**
     * Extract members from document text using regex.
     *
     * Also includes inherited members from parent classes when resolvable.
     *
     * @return list<ResolvedMember>
     */
    public function extractMembers(
        TextDocument $document,
        ClassName $className,
        Visibility $minVisibility,
        MemberFilter $filter = MemberFilter::Instance,
    ): array {
        $content = $document->getContent();
        $members = [];
        $includeStatic = $filter !== MemberFilter::Instance;

        // Match class declaration with optional extends clause
        $classPattern = '/(?:class|interface|trait|enum)\s+' . preg_quote($className->shortName(), '/') . '\b'
            . '(?:\s+extends\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*))?/';
        if (preg_match($classPattern, $content, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }
        $classContent = $this->sliceClassBody($content, $match[0][1]);

        // Extract members directly defined in this class
        $this->extractMethods($classContent, $className, $minVisibility, $filter, $includeStatic, $members);
        $this->extractProperties($classContent, $className, $minVisibility, $filter, $includeStatic, $members);
        $this->extractConstants($classContent, $className, $minVisibility, $includeStatic, $members);

        // Include inherited members from parent class if resolvable
        if (isset($match[1]) && $match[1][0] !== '') {
            $parentName = $match[1][0];
            $parentMembers = $this->getInheritedMembers($document, $parentName, $minVisibility, $filter);
            $members = $this->mergeUniqueMembers($members, $parentMembers);
        }

        return $members;
    }

    /**
     * Merge inherited members into the members already collected, skipping any the
     * subclass overrides (same member kind and name).
     *
     * @param list<ResolvedMember> $members
     * @param list<ResolvedMember> $inherited
     * @return list<ResolvedMember>
     */
    private function mergeUniqueMembers(array $members, array $inherited): array
    {
        $seen = [];
        foreach ($members as $member) {
            $seen[self::memberKey($member)] = true;
        }
        foreach ($inherited as $member) {
            $key = self::memberKey($member);
            if (!array_key_exists($key, $seen)) {
                $seen[$key] = true;
                $members[] = $member;
            }
        }
        return $members;
    }

    private static function memberKey(ResolvedMember $member): string
    {
        return $member->getMemberKind()->keyFor($member->getName()->name);
    }

    /**
     * Slice a single class body, from its declaration through the matching
     * closing brace, so member extraction cannot leak into sibling classes
     * defined later in the same file.
     */
    private function sliceClassBody(string $content, int $declOffset): string
    {
        $bracePos = strpos($content, '{', $declOffset);
        if ($bracePos !== false) {
            $depth = 0;
            for ($i = $bracePos, $length = strlen($content); $i < $length; $i++) {
                $char = $content[$i];
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}' && --$depth === 0) {
                    return substr($content, $declOffset, $i - $declOffset + 1);
                }
            }
        }

        // No opening brace or unbalanced braces (incomplete code): the class body
        // runs to the end of the document.
        return substr($content, $declOffset);
    }

    /**
     * Get inherited members from a parent class.
     *
     * @return list<ResolvedMember>
     */
    private function getInheritedMembers(
        TextDocument $document,
        string $parentName,
        Visibility $minVisibility,
        MemberFilter $filter,
    ): array {
        $members = [];
        $lines = explode("\n", $document->getContent());

        // Resolve parent class name using use statements (no AST available here)
        $context = NameContextFactory::fromText($lines, 0);
        $fqn = $context->candidates($parentName, NameKind::ClassLike)[0];

        // Get parent members via MemberResolver
        // @phpstan-ignore argument.type (text-based resolution cannot guarantee class-string)
        $parentClassName = new ClassName($fqn);

        // A subclass cannot access its parent's private members, so never query the
        // parent below Protected visibility (while still honoring an external Public
        // access level).
        $inheritedVisibility = Visibility::from(max($minVisibility->value, Visibility::Protected->value));

        $methods = $this->memberResolver->getMethods($parentClassName, $inheritedVisibility, $filter);
        foreach ($methods as $methodInfo) {
            $members[] = new ResolvedMethod($methodInfo);
        }

        if ($filter !== MemberFilter::Static) {
            $properties = $this->memberResolver->getProperties($parentClassName, $inheritedVisibility, $filter);
            foreach ($properties as $propertyInfo) {
                $members[] = new ResolvedProperty($propertyInfo);
            }
        }

        if ($filter !== MemberFilter::Instance) {
            $constants = $this->memberResolver->getConstants($parentClassName, $inheritedVisibility);
            foreach ($constants as $constantInfo) {
                $members[] = new ResolvedConstant($constantInfo);
            }
        }

        return $members;
    }

    /**
     * @param list<ResolvedMember> $members
     */
    private function extractMethods(
        string $classContent,
        ClassName $className,
        Visibility $minVisibility,
        MemberFilter $filter,
        bool $includeStatic,
        array &$members,
    ): void {
        $pattern = '/^\s*(public|protected|private)\s+(static\s+)?function\s+(\w+)\s*\(/m';
        if (preg_match_all($pattern, $classContent, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $visibility = Visibility::fromString($match[1]);
                if (!$visibility->isAccessibleFrom($minVisibility)) {
                    continue;
                }
                $isStatic = $match[2] !== '';
                $includeThis = $filter === MemberFilter::All
                    || ($isStatic && $includeStatic)
                    || (!$isStatic && !$includeStatic);
                if ($includeThis) {
                    $members[] = new ResolvedMethod(new MethodInfo(
                        name: new MethodName($match[3]),
                        visibility: $visibility,
                        isStatic: $isStatic,
                        isAbstract: false,
                        isFinal: false,
                        parameters: [],
                        returnType: null,
                        declaringClass: $className,
                        docblock: null,
                        file: null,
                        line: null,
                    ));
                }
            }
        }
    }

    /**
     * @param list<ResolvedMember> $members
     */
    private function extractProperties(
        string $classContent,
        ClassName $className,
        Visibility $minVisibility,
        MemberFilter $filter,
        bool $includeStatic,
        array &$members,
    ): void {
        if ($filter === MemberFilter::Static) {
            return;
        }

        $pattern = '/^\s*(public|protected|private)\s+(static\s+)?(readonly\s+)?(?:[\w\\\\|?]+\s+)?\$(\w+)/m';
        if (preg_match_all($pattern, $classContent, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $visibility = Visibility::fromString($match[1]);
                if (!$visibility->isAccessibleFrom($minVisibility)) {
                    continue;
                }
                $isStatic = $match[2] !== '';
                if (!$isStatic || $includeStatic) {
                    $members[] = new ResolvedProperty(new PropertyInfo(
                        name: new PropertyName($match[4]),
                        visibility: $visibility,
                        isStatic: $isStatic,
                        isReadonly: $match[3] !== '',
                        isPromoted: false,
                        type: null,
                        docblock: null,
                        file: null,
                        line: null,
                        declaringClass: $className,
                    ));
                }
            }
        }
    }

    /**
     * @param list<ResolvedMember> $members
     */
    private function extractConstants(
        string $classContent,
        ClassName $className,
        Visibility $minVisibility,
        bool $includeStatic,
        array &$members,
    ): void {
        if (!$includeStatic) {
            return;
        }

        // Captures: 1=visibility (optional), 2=constant name
        // Handles PHP 8.1+ typed constants: public const string NAME = ...
        $pattern = '/^\s*(public|protected|private)?\s*const\s+(?:[\w\\\\|?]+\s+)?(\w+)\s*=/m';
        if (preg_match_all($pattern, $classContent, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $visibility = ($match[1] !== '') ? Visibility::fromString($match[1]) : Visibility::Public;
                if (!$visibility->isAccessibleFrom($minVisibility)) {
                    continue;
                }
                $members[] = new ResolvedConstant(new ConstantInfo(
                    name: new ConstantName($match[2]),
                    visibility: $visibility,
                    isFinal: false,
                    type: null,
                    docblock: null,
                    file: null,
                    line: null,
                    declaringClass: $className,
                ));
            }
        }
    }
}
