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

/**
 * Text-based fallback for code resolution when AST-based detection fails.
 *
 * This is an internal helper for SymbolResolver, compensating for cases where
 * PHP-Parser drops incomplete code (e.g., `if ($this->|)`). Uses regex patterns
 * to detect member access and extract basic member information.
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
     * Detect member access pattern from line text.
     *
     * @return array{varName: string, isNullsafe: bool, prefix: string, isChained: bool, chainExpr: string|null}|null
     */
    public function detectInstanceAccess(string $lineText, int $character): ?array
    {
        $textBeforeCursor = substr($lineText, 0, $character);

        // Chained instance access: $var->member->prefix or $var?->member->prefix
        $chainPattern = '/(\$\w+(?:\??->[\w]+(?:\([^)]*\))?)+)\??->([\w]*)$/';
        if (preg_match($chainPattern, $textBeforeCursor, $matches) === 1) {
            return [
                'varName' => 'this', // Chain detection only supports $this currently
                'isNullsafe' => false,
                'prefix' => $matches[2],
                'isChained' => true,
                'chainExpr' => $matches[1],
            ];
        }

        // Simple instance access: $var->prefix or $var?->prefix
        if (preg_match('/\$(\w+)(\?)?->([\w]*)$/', $textBeforeCursor, $matches) === 1) {
            return [
                'varName' => $matches[1],
                'isNullsafe' => $matches[2] === '?',
                'prefix' => $matches[3],
                'isChained' => false,
                'chainExpr' => null,
            ];
        }

        return null;
    }

    /**
     * Detect static access pattern from line text.
     *
     * @return array{className: string, prefix: string}|null
     */
    public function detectStaticAccess(string $lineText, int $character): ?array
    {
        $textBeforeCursor = substr($lineText, 0, $character);

        // Static access: ClassName::prefix or self::prefix or static::prefix or parent::prefix
        // Negative lookbehind (?<!\$) excludes $variable:: (dynamic class names)
        if (preg_match('/(?<!\$)([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::([\w]*)$/', $textBeforeCursor, $matches) === 1) {
            return [
                'className' => $matches[1],
                'prefix' => $matches[2],
            ];
        }

        return null;
    }

    /**
     * Find enclosing class name by scanning document text backwards.
     *
     * @return class-string|null
     */
    public function findEnclosingClass(TextDocument $document, int $line): ?string
    {
        return $this->findEnclosingClassFromContent($document->getContent(), $line);
    }

    /**
     * Find enclosing class name by scanning content text backwards.
     *
     * @return class-string|null
     */
    public function findEnclosingClassFromContent(string $content, int $line): ?string
    {
        $lines = explode("\n", $content);

        // Scan backwards from current line looking for class/trait/enum declaration
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
     * Resolve the type of a chained expression like $this->logger or $this->getLogger().
     *
     * @param class-string $thisClass The class that $this refers to
     */
    public function resolveChainType(string $chainExpr, string $thisClass): ?Type
    {
        // Only support chains starting with $this
        if (!str_starts_with($chainExpr, '$this->')) {
            return null;
        }

        // Remove $this-> prefix
        $chain = substr($chainExpr, 7); // strlen('$this->') = 7

        // Split into parts: "logger" or "getLogger()" etc.
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

            $className = $classNames[0];

            // First part accessed via $this uses Private visibility, subsequent use Public
            $visibility = $isFirstPart ? Visibility::Private : Visibility::Public;
            $isFirstPart = false;

            if ($isMethodCall) {
                $methodInfo = $this->memberResolver->findMethod(
                    $className,
                    new MethodName($memberName),
                    $visibility,
                );
                if ($methodInfo === null) {
                    return null;
                }
                $currentType = $methodInfo->returnType;
            } else {
                $propertyInfo = $this->memberResolver->findProperty(
                    $className,
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
     * Extract members from document text using regex.
     *
     * Used when AST-based member resolution fails completely.
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

        // Find the class declaration to scope our search
        $classPattern = '/(?:class|trait|enum)\s+' . preg_quote($className->shortName(), '/') . '\b/';
        if (preg_match($classPattern, $content, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }
        $classStart = $match[0][1];
        $classContent = substr($content, $classStart);

        $this->extractMethods($classContent, $className, $minVisibility, $filter, $includeStatic, $members);
        $this->extractProperties($classContent, $className, $minVisibility, $filter, $includeStatic, $members);
        $this->extractConstants($classContent, $className, $includeStatic, $members);

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
        $methodPattern = '/^\s*(public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(/m';
        if (preg_match_all($methodPattern, $classContent, $methodMatches, PREG_SET_ORDER) > 0) {
            foreach ($methodMatches as $methodMatch) {
                $visibility = Visibility::fromString($methodMatch[1]);
                if (!$visibility->isAccessibleFrom($minVisibility)) {
                    continue;
                }
                $isStatic = str_contains($methodMatch[0], 'static');
                $includeThis = $filter === MemberFilter::All
                    || ($isStatic && $includeStatic)
                    || (!$isStatic && !$includeStatic);
                if ($includeThis) {
                    $methodInfo = new MethodInfo(
                        name: new MethodName($methodMatch[2]),
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
                    );
                    $members[] = new ResolvedMethod($methodInfo);
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

        $propertyPattern = '/^\s*(public|protected|private)\s+'
            . '(?:static\s+)?(?:readonly\s+)?(?:[\w\\\\|?]+\s+)?\$(\w+)/m';
        if (preg_match_all($propertyPattern, $classContent, $propMatches, PREG_SET_ORDER) > 0) {
            foreach ($propMatches as $propMatch) {
                $visibility = Visibility::fromString($propMatch[1]);
                if (!$visibility->isAccessibleFrom($minVisibility)) {
                    continue;
                }
                $isStatic = str_contains($propMatch[0], 'static');
                if (!$isStatic || $includeStatic) {
                    $propertyInfo = new PropertyInfo(
                        name: new PropertyName($propMatch[2]),
                        visibility: $visibility,
                        isStatic: $isStatic,
                        isReadonly: str_contains($propMatch[0], 'readonly'),
                        isPromoted: false,
                        type: null,
                        docblock: null,
                        file: null,
                        line: null,
                        declaringClass: $className,
                    );
                    $members[] = new ResolvedProperty($propertyInfo);
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
        bool $includeStatic,
        array &$members,
    ): void {
        if (!$includeStatic) {
            return;
        }

        $constPattern = '/^\s*(?:public|protected|private)?\s*const\s+(\w+)\s*=/m';
        if (preg_match_all($constPattern, $classContent, $constMatches, PREG_SET_ORDER) > 0) {
            foreach ($constMatches as $constMatch) {
                $constantInfo = new ConstantInfo(
                    name: new ConstantName($constMatch[1]),
                    visibility: Visibility::Public,
                    isFinal: false,
                    type: null,
                    docblock: null,
                    file: null,
                    line: null,
                    declaringClass: $className,
                );
                $members[] = new ResolvedConstant($constantInfo);
            }
        }
    }
}
