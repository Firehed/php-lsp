<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\UnionType;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Utility\ScopeFinder;
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
     * Detect and resolve member access context from text.
     *
     * @param array<Stmt> $ast AST for namespace/use resolution (may be partial)
     */
    public function getMemberAccessContext(
        TextDocument $document,
        int $line,
        int $character,
        array $ast,
    ): ?MemberAccessContext {
        $lineText = $document->getLine($line);
        $textBeforeCursor = substr($lineText, 0, $character);

        // Chained instance access: $this->member->prefix or $this?->member->prefix
        if (preg_match('/(\$this(?:\??->[\w]+(?:\([^)]*\))?)+)\??->([\w]*)$/', $textBeforeCursor, $m) === 1) {
            return $this->resolveChainedAccess($m[1], $m[2], $document, $line);
        }

        // Simple instance access: $var->prefix or $var?->prefix
        if (preg_match('/\$(\w+)(\?)?->([\w]*)$/', $textBeforeCursor, $m) === 1) {
            return $this->resolveInstanceAccess($m[1], $m[3], $document, $line);
        }

        // Static access: ClassName::prefix (excluding $var::)
        if (preg_match('/(?<!\$)([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::([\w]*)$/', $textBeforeCursor, $m) === 1) {
            return $this->resolveStaticAccess($m[1], $m[2], $document, $line, $ast);
        }

        return null;
    }

    /**
     * Resolve simple instance access ($var-> or $this->).
     */
    private function resolveInstanceAccess(
        string $varName,
        string $prefix,
        TextDocument $document,
        int $line,
    ): ?MemberAccessContext {
        // Only $this can be resolved via pure text - other variables need AST
        if ($varName !== 'this') {
            return null;
        }

        $enclosingClass = $this->findEnclosingClass($document, $line);
        if ($enclosingClass === null) {
            // $this outside any class - no completion possible
            return null;
        }

        return new MemberAccessContext(
            new ClassName($enclosingClass),
            Visibility::Private,
            MemberAccessKind::Instance,
            $prefix,
        );
    }

    /**
     * Resolve chained access ($this->member-> or $this->method()->).
     */
    private function resolveChainedAccess(
        string $chainExpr,
        string $prefix,
        TextDocument $document,
        int $line,
    ): ?MemberAccessContext {
        $enclosingClass = $this->findEnclosingClass($document, $line);
        if ($enclosingClass === null) {
            // Chained $this access outside any class - no completion possible
            return null;
        }

        $type = $this->resolveChainType($chainExpr, $enclosingClass);
        if ($type === null) {
            // Chain resolution failed - member not found or untyped
            return null;
        }

        return new MemberAccessContext($type, Visibility::Public, MemberAccessKind::Instance, $prefix);
    }

    /**
     * Resolve static access (self::, static::, ClassName::).
     *
     * @param array<Stmt> $ast
     */
    private function resolveStaticAccess(
        string $className,
        string $prefix,
        TextDocument $document,
        int $line,
        array $ast,
    ): ?MemberAccessContext {
        $enclosingClass = $this->findEnclosingClass($document, $line);
        $lowerClassName = strtolower($className);

        // self:: and static:: resolve to enclosing class
        if ($lowerClassName === 'self' || $lowerClassName === 'static') {
            if ($enclosingClass === null) {
                // self::/static:: outside class - no completion possible
                return null;
            }
            return new MemberAccessContext(
                new ClassName($enclosingClass),
                Visibility::Private,
                MemberAccessKind::Static,
                $prefix,
            );
        }

        // parent:: requires AST to find extends clause
        if ($lowerClassName === 'parent') {
            $classNode = ScopeFinder::findClassAtLine($ast, $line);
            if ($classNode === null) {
                // parent:: outside any class - no completion possible
                return null;
            }
            $parentClassName = ScopeFinder::resolveExtendsName($classNode);
            if ($parentClassName === null) {
                return null;
            }
            return new MemberAccessContext(
                new ClassName($parentClassName),
                Visibility::Protected,
                MemberAccessKind::Parent,
                $prefix,
            );
        }

        // Regular class name - resolve via use statements
        $lines = explode("\n", $document->getContent());
        $fqn = $this->resolveClassName($className, $lines, $ast, $line);

        // Determine visibility based on whether we're inside the same class
        $isSameClass = $enclosingClass !== null && $enclosingClass === $fqn;
        $visibility = $isSameClass ? Visibility::Private : Visibility::Public;

        return new MemberAccessContext(
            // @phpstan-ignore argument.type (text-based resolution cannot guarantee class-string)
            new ClassName($fqn),
            $visibility,
            MemberAccessKind::Static,
            $prefix,
        );
    }

    /**
     * Resolve the type of a chained expression like $this->logger or $this->getLogger().
     *
     * @param class-string $thisClass
     */
    public function resolveChainType(string $chainExpr, string $thisClass): ?Type
    {
        if (!str_starts_with($chainExpr, '$this->')) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('resolveChainType called without $this-> prefix');
            // @codeCoverageIgnoreEnd
        }

        $chain = substr($chainExpr, 7); // strlen('$this->') = 7
        $parts = preg_split('/\??->/', $chain);
        if ($parts === false || $parts === []) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('preg_split with valid pattern cannot fail');
            // @codeCoverageIgnoreEnd
        }

        $currentType = new ClassName($thisClass);
        $isFirstPart = true;

        foreach ($parts as $part) {
            // Empty parts can occur with trailing -> in incomplete code
            if ($part === '') {
                continue;
            }

            $isMethodCall = str_contains($part, '(');
            $memberName = $isMethodCall ? strstr($part, '(', true) : $part;
            if ($memberName === false || $memberName === '') {
                // @codeCoverageIgnoreStart
                throw new \LogicException('strstr cannot return false when ( is present');
                // @codeCoverageIgnoreEnd
            }

            $classNames = $currentType->getResolvableClassNames();
            if ($classNames === []) {
                // Type resolved to primitive or union without classes - can't continue
                return null;
            }

            $visibility = $isFirstPart ? Visibility::Private : Visibility::Public;
            $isFirstPart = false;

            if ($isMethodCall) {
                $methodInfo = $this->memberResolver->findMethod(
                    $classNames[0],
                    new MethodName($memberName),
                    $visibility,
                );
                if ($methodInfo === null) {
                    return null;
                }
                $currentType = $methodInfo->returnType;
            } else {
                $propertyInfo = $this->memberResolver->findProperty(
                    $classNames[0],
                    new PropertyName($memberName),
                    $visibility,
                );
                if ($propertyInfo === null) {
                    return null;
                }
                $currentType = $propertyInfo->type;
            }

            if ($currentType === null) {
                // Untyped method return or property - can't continue chain
                return null;
            }
        }

        return $currentType;
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
     * Find the type of a parameter by scanning backwards for method declaration.
     *
     * @param array<Stmt> $ast AST for use statement resolution
     */
    public function findParameterType(
        TextDocument $document,
        int $line,
        string $varName,
        array $ast,
    ): ?Type {
        $content = $document->getContent();
        $lines = explode("\n", $content);

        // Scan backwards to find method/function declaration
        for ($i = $line; $i >= 0; $i--) {
            $lineText = $lines[$i] ?? '';

            // Match function/method declaration with parameters
            // Handles multi-line declarations by accumulating lines
            if (preg_match('/function\s+\w+\s*\(/', $lineText) === 1) {
                // Accumulate lines until we find closing paren
                $declaration = $lineText;
                for ($j = $i; $j < min($i + 10, count($lines)); $j++) {
                    if ($j > $i) {
                        $declaration .= ' ' . $lines[$j];
                    }
                    if (str_contains($declaration, ')')) {
                        break;
                    }
                }

                // Extract parameter type for the variable
                $type = $this->extractParameterType($declaration, $varName, $lines, $ast, $line);
                if ($type !== null) {
                    return $type;
                }
                break;
            }
        }

        return null;
    }

    private const PRIMITIVES = [
        'string',
        'int',
        'float',
        'bool',
        'array',
        'object',
        'callable',
        'iterable',
        'void',
        'never',
        'mixed',
        'null',
        'true',
        'false',
    ];

    /**
     * Extract parameter type from a function declaration string.
     *
     * @param list<string> $lines
     * @param array<Stmt> $ast
     */
    private function extractParameterType(
        string $declaration,
        string $varName,
        array $lines,
        array $ast,
        int $line,
    ): ?Type {
        // Pattern: TypeName $varName, ?TypeName $varName, or A|B $varName
        $pattern = '/([?A-Za-z_\\\\][A-Za-z0-9_\\\\|?]*)\s+\$' . preg_quote($varName, '/') . '\b/';
        if (preg_match($pattern, $declaration, $matches) !== 1) {
            return null;
        }

        // Resolve each union member; primitives are skipped since they have no members
        $classTypes = [];
        foreach (explode('|', $matches[1]) as $part) {
            $part = ltrim($part, '?');
            if ($part === '' || in_array(strtolower($part), self::PRIMITIVES, true)) {
                continue;
            }
            $fqn = $this->resolveClassName($part, $lines, $ast, $line);
            /** @phpstan-ignore argument.type (text-based resolution cannot guarantee class-string) */
            $classTypes[] = new ClassName($fqn);
        }

        if ($classTypes === []) {
            return null;
        }
        if (count($classTypes) === 1) {
            return $classTypes[0];
        }
        return new UnionType($classTypes);
    }

    /**
     * Resolve a class name using use statements.
     *
     * Tries AST-based resolution first, falls back to text-based when AST is empty.
     *
     * @param list<string> $lines Document lines for text-based fallback
     * @param array<Stmt> $ast
     */
    private function resolveClassName(string $className, array $lines, array $ast, int $line): string
    {
        // Already fully qualified
        if (str_starts_with($className, '\\')) {
            return ltrim($className, '\\');
        }

        // Try AST-based resolution first
        $resolved = ScopeFinder::resolveFromUseStatements($className, $ast);
        if ($resolved !== null) {
            return $resolved;
        }

        // Fall back to text-based use statement search
        $resolved = $this->resolveFromUseStatementsText($className, $lines);
        if ($resolved !== null) {
            return $resolved;
        }

        // Prepend current namespace (try AST first, then text)
        $namespace = ScopeFinder::findNamespaceAtLine($ast, $line)
            ?? $this->findNamespace($lines, $line);
        if ($namespace !== null) {
            return $namespace . '\\' . $className;
        }

        // No namespace - return raw class name (global namespace)
        return $className;
    }

    /**
     * Text-based use statement resolution for when AST is unavailable.
     *
     * Handles simple use, aliased use, and group use syntax.
     * For partially qualified names (e.g., B\CDE where B is aliased),
     * resolves the first segment and appends the rest.
     *
     * @param list<string> $lines
     */
    private function resolveFromUseStatementsText(string $className, array $lines): ?string
    {
        // Check if className is partially qualified (e.g., B\CDE)
        $parts = explode('\\', $className);
        $firstPart = $parts[0];
        $isPartiallyQualified = count($parts) > 1;

        // Build map of all imports
        $imports = $this->extractUseStatementsFromText($lines);

        // Check for exact match first
        if (isset($imports[$className])) {
            return $imports[$className];
        }

        // For partially qualified names (e.g., Alias\SubClass), resolve first segment
        if ($isPartiallyQualified && isset($imports[$firstPart])) {
            $remainder = implode('\\', array_slice($parts, 1));
            return $imports[$firstPart] . '\\' . $remainder;
        }

        return null;
    }

    /**
     * Extract all use statements from source lines into alias => FQN map.
     *
     * @param list<string> $lines
     * @return array<string, string> Map of alias/simple name to FQN
     */
    private function extractUseStatementsFromText(array $lines): array
    {
        $imports = [];

        $classDecl = '/^\s*(?:abstract\s+|final\s+|readonly\s+)*(?:class|interface|trait|enum)\s+/';
        $name = '[A-Za-z_\\\\][A-Za-z0-9_\\\\]*';
        $simpleName = '[A-Za-z_][A-Za-z0-9_]*';

        foreach ($lines as $lineText) {
            // Stop at class/interface/trait/enum declaration
            if (preg_match($classDecl, $lineText) === 1) {
                break;
            }

            // Skip non-use lines
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
                    // Item with alias: Something as Alias
                    $aliasPattern = '/^(' . $name . ')\s+as\s+(' . $simpleName . ')$/';
                    if (preg_match($aliasPattern, $item, $im) === 1) {
                        $imports[$im[2]] = $prefix . '\\' . $im[1];
                    } else {
                        // Simple item or nested: Something or Sub\Thing
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
            $seen[$member::class . ':' . $member->getName()->name] = true;
        }
        foreach ($inherited as $member) {
            $key = $member::class . ':' . $member->getName()->name;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $members[] = $member;
            }
        }
        return $members;
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

        // Resolve parent class name using use statements
        $fqn = $this->resolveClassName($parentName, $lines, [], 0);

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
