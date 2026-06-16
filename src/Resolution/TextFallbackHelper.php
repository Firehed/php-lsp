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
            return null;
        }

        $type = $this->resolveChainType($chainExpr, $enclosingClass);
        if ($type === null) {
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
            return null;
        }

        $chain = substr($chainExpr, 7); // strlen('$this->') = 7
        $parts = preg_split('/\??->/', $chain);
        if ($parts === false || $parts === []) {
            return null;
        }

        $currentType = new ClassName($thisClass);
        $isFirstPart = true;

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $isMethodCall = str_contains($part, '(');
            $memberName = $isMethodCall ? strstr($part, '(', true) : $part;
            if ($memberName === false || $memberName === '') {
                return null;
            }

            $classNames = $currentType->getResolvableClassNames();
            if ($classNames === []) {
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

        $classPattern = '/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|trait|enum)\s+(\w+)/i';
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
        // Pattern: TypeName $varName or ?TypeName $varName
        $pattern = '/(\??[A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s+\$' . preg_quote($varName, '/') . '\b/';
        if (preg_match($pattern, $declaration, $matches) !== 1) {
            return null;
        }

        $typeStr = $matches[1];
        $nullable = str_starts_with($typeStr, '?');
        if ($nullable) {
            $typeStr = substr($typeStr, 1);
        }

        // Primitive types have no members - return null to skip member access
        if (in_array(strtolower($typeStr), self::PRIMITIVES, true)) {
            return null;
        }

        // Resolve the class name using use statements
        $fqn = $this->resolveClassName($typeStr, $lines, $ast, $line);

        /** @phpstan-ignore argument.type (text-based resolution cannot guarantee class-string) */
        return new ClassName($fqn);
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
        $namespace = $this->findNamespaceForLine($ast, $line)
            ?? $this->findNamespace($lines, $line);
        if ($namespace !== null) {
            return $namespace . '\\' . $className;
        }

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

        // For partially qualified names, resolve first segment
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
                    continue;
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
                        $lastPart = substr($item, (int)strrpos($item, '\\') + 1);
                        if ($lastPart === '') {
                            $lastPart = $item;
                        }
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
                $lastPart = substr($fqn, (int)strrpos($fqn, '\\') + 1);
                $imports[$lastPart] = $fqn;
            }
        }

        return $imports;
    }

    /**
     * Find namespace for a given line from AST.
     *
     * @param array<Stmt> $ast
     */
    private function findNamespaceForLine(array $ast, int $line): ?string
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                $startLine = $stmt->getStartLine();
                $endLine = $stmt->getEndLine();
                if ($startLine <= $line && $line <= $endLine) {
                    return $stmt->name?->toString();
                }
            }
        }
        return null;
    }

    /**
     * Extract members from document text using regex.
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

        $classPattern = '/(?:class|trait|enum)\s+' . preg_quote($className->shortName(), '/') . '\b/';
        if (preg_match($classPattern, $content, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }
        $classContent = substr($content, $match[0][1]);

        $this->extractMethods($classContent, $className, $minVisibility, $filter, $includeStatic, $members);
        $this->extractProperties($classContent, $className, $minVisibility, $filter, $includeStatic, $members);
        $this->extractConstants($classContent, $className, $minVisibility, $includeStatic, $members);

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
        $pattern = '/^\s*(public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(/m';
        if (preg_match_all($pattern, $classContent, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $visibility = Visibility::fromString($match[1]);
                if (!$visibility->isAccessibleFrom($minVisibility)) {
                    continue;
                }
                $isStatic = str_contains($match[0], 'static');
                $includeThis = $filter === MemberFilter::All
                    || ($isStatic && $includeStatic)
                    || (!$isStatic && !$includeStatic);
                if ($includeThis) {
                    $members[] = new ResolvedMethod(new MethodInfo(
                        name: new MethodName($match[2]),
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

        $pattern = '/^\s*(public|protected|private)\s+(?:static\s+)?(?:readonly\s+)?(?:[\w\\\\|?]+\s+)?\$(\w+)/m';
        if (preg_match_all($pattern, $classContent, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $visibility = Visibility::fromString($match[1]);
                if (!$visibility->isAccessibleFrom($minVisibility)) {
                    continue;
                }
                $isStatic = str_contains($match[0], 'static');
                if (!$isStatic || $includeStatic) {
                    $members[] = new ResolvedProperty(new PropertyInfo(
                        name: new PropertyName($match[2]),
                        visibility: $visibility,
                        isStatic: $isStatic,
                        isReadonly: str_contains($match[0], 'readonly'),
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
