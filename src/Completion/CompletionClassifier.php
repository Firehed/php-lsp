<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

/**
 * Classifies a cursor position into a {@see CompletionKind} using only the text
 * before the cursor.
 *
 * Detection is deliberately text-based rather than AST-based: this is a live
 * language server, and completion must keep working while code is temporarily
 * broken mid-edit (see CompletionHandlerTest::testCompletionThisInVeryBrokenFile,
 * where the parser produces no AST at all). Member, static, and call-argument
 * contexts are AST-first with a text fallback and are detected upstream via
 * {@see \Firehed\PhpLsp\Resolution\CodeResolver}, so they never reach here.
 *
 * The ordering of checks is significant: earlier, more specific patterns must be
 * tested before later, broader ones (e.g. a visibility keyword before the general
 * type-hint fallback).
 */
final class CompletionClassifier
{
    // Matches property type continuations: "private ?", "public int|", "protected Foo&"
    private const PROPERTY_TYPE_PATTERN = '/(?:public|private|protected)\s+(?:readonly\s+)?(?:\w+\s*)?[?|&]\s*(\w*)$/';

    // Matches an "interface X extends …" list (single or comma-separated).
    private const INTERFACE_EXTENDS_PATTERN = '/\binterface\s+\w+\s+extends\s+(?:[\w\\\\]+\s*,\s*)*(\w*)$/';

    // Matches a "class X extends …" clause. A class extends exactly one class, so
    // there is no comma-list form.
    private const CLASS_EXTENDS_PATTERN = '/\bclass\s+\w+\s+extends\s+(\w*)$/';

    // Matches a catch clause's type position, including the `|`-separated multi-catch
    // continuation ("catch (Foo | Ba"). The caught variable is matched by the variable
    // pattern, which is checked first, so this only sees type positions.
    private const CATCH_PATTERN = '/\bcatch\s*\(\s*(?:[\w\\\\]+\s*\|\s*)*(\w*)$/';

    // Matches an attribute-name position: "#[", including grouped attributes
    // ("#[Foo, Ba", "#[Foo(1), Ba"). It deliberately does not match inside an
    // attribute's own argument list ("#[Foo(Ba"), which is a value position.
    private const ATTRIBUTE_PATTERN = '/#\[\s*(?:[\w\\\\]+\s*(?:\([^)]*\))?\s*,\s*)*(\w*)$/';

    public static function classify(string $textBeforeCursor): CompletionClassification
    {
        // Variable completion
        if (preg_match('/\$(\w*)$/', $textBeforeCursor, $matches) === 1) {
            return new CompletionClassification(CompletionKind::Variable, $matches[1]);
        }

        // new ClassName completion. The prefix keeps a leading `\` and embedded
        // separators so a qualified/navigated name (`new \Ps`, `new Psr\Ht`) reaches
        // the handler intact rather than being truncated to its last segment.
        if (preg_match('/new\s+(\\\\?[\w\\\\]*)$/', $textBeforeCursor, $matches) === 1) {
            return new CompletionClassification(CompletionKind::New_, $matches[1]);
        }

        // Attribute position (#[). Checked early: the "#[" delimiter is unambiguous,
        // and attributes appear in class bodies and type-hint-adjacent positions that
        // later, broader patterns would otherwise claim.
        if (preg_match(self::ATTRIBUTE_PATTERN, $textBeforeCursor, $matches) === 1) {
            return new CompletionClassification(CompletionKind::Attribute, $matches[1]);
        }

        // implements list - interfaces only. Must check before the parameter-type
        // fallback, since the comma in "implements A, Ba" also matches that pattern.
        if (preg_match('/\bimplements\s+(?:[\w\\\\]+\s*,\s*)*(\w*)$/', $textBeforeCursor, $matches) === 1) {
            return new CompletionClassification(CompletionKind::InterfaceList, $matches[1]);
        }

        // interface X extends list - interfaces only (an interface may extend many).
        // The literal `interface` keyword distinguishes this from `class X extends`,
        // which resolves to a single class. Like implements, the comma-list form must
        // be checked before the parameter-type fallback.
        if (preg_match(self::INTERFACE_EXTENDS_PATTERN, $textBeforeCursor, $matches) === 1) {
            return new CompletionClassification(CompletionKind::InterfaceList, $matches[1]);
        }

        // class X extends - a single extendable class. The literal `class` keyword
        // and lack of a comma-list distinguish this from `interface X extends`.
        if (preg_match(self::CLASS_EXTENDS_PATTERN, $textBeforeCursor, $matches) === 1) {
            return new CompletionClassification(CompletionKind::ExtendableClass, $matches[1]);
        }

        // catch clause - Throwable types only. Must check before the parameter-type
        // fallback, since the "(" and "|" in "catch (Foo | Ba" also match it.
        if (preg_match(self::CATCH_PATTERN, $textBeforeCursor, $matches) === 1) {
            return new CompletionClassification(CompletionKind::Throwable, $matches[1]);
        }

        // After visibility keyword - keywords or property type.
        // Must check before the general type hint fallback since both patterns overlap.
        if (preg_match('/(?:public|private|protected)\s+(\w*)$/', $textBeforeCursor, $matches) === 1) {
            return new CompletionClassification(CompletionKind::AfterVisibility, $matches[1]);
        }

        // Return type context - after ): with optional space
        if (preg_match('/\):\s*(\w*)$/', $textBeforeCursor, $matches) === 1) {
            return new CompletionClassification(CompletionKind::ReturnType, $matches[1]);
        }

        // Return type context - nullable/union/intersection (e.g., "): ?", "): int|", "): Foo&")
        if (preg_match('/\):\s*(?:\?\s*|(?:\w+\s*[|&]\s*)+)(\w*)$/', $textBeforeCursor, $matches) === 1) {
            return new CompletionClassification(CompletionKind::ReturnType, $matches[1]);
        }

        // Property type context - nullable/union/intersection after visibility keyword
        if (preg_match(self::PROPERTY_TYPE_PATTERN, $textBeforeCursor, $matches) === 1) {
            return new CompletionClassification(CompletionKind::PropertyType, $matches[1]);
        }

        // Parameter type context - fallback for type positions not matched above.
        // Matches after (, ,, ?, |, & which occur in parameter lists and complex types
        if (preg_match('/[(,?|&]\s*(\w*)$/', $textBeforeCursor, $matches) === 1) {
            return new CompletionClassification(CompletionKind::ParameterType, $matches[1]);
        }

        // Class body context - only class-level keywords, no functions
        if (self::isInClassBody($textBeforeCursor)) {
            if (preg_match('/(?:^|[\s{;])(\w+)$/', $textBeforeCursor, $matches) === 1) {
                return new CompletionClassification(CompletionKind::ClassBody, $matches[1]);
            }
            return new CompletionClassification(CompletionKind::None, '');
        }

        // Function/class/keyword completion (at start of expression or after operators)
        if (preg_match('/(?:^|[(\s=,!&|])(\w+)$/', $textBeforeCursor, $matches) === 1) {
            return new CompletionClassification(CompletionKind::Expression, $matches[1]);
        }

        return new CompletionClassification(CompletionKind::None, '');
    }

    /**
     * Check if cursor is inside a class/interface/trait/enum body (but not inside a method).
     */
    private static function isInClassBody(string $textBeforeCursor): bool
    {
        // Count braces to detect if we're inside a class body
        // This is a heuristic - look for class/interface/trait/enum followed by unbalanced {
        if (preg_match('/(?:class|interface|trait|enum)\s+\w+/', $textBeforeCursor) !== 1) {
            return false;
        }

        // Count brace depth after the class declaration
        $classPos = strrpos($textBeforeCursor, 'class ');
        $interfacePos = strrpos($textBeforeCursor, 'interface ');
        $traitPos = strrpos($textBeforeCursor, 'trait ');
        $enumPos = strrpos($textBeforeCursor, 'enum ');
        $lastClassPos = max(
            $classPos !== false ? $classPos : 0,
            $interfacePos !== false ? $interfacePos : 0,
            $traitPos !== false ? $traitPos : 0,
            $enumPos !== false ? $enumPos : 0,
        );

        $afterClass = substr($textBeforeCursor, $lastClassPos);
        $depth = 0;
        $inString = false;
        $stringChar = '';

        for ($i = 0; $i < strlen($afterClass); $i++) {
            $char = $afterClass[$i];

            if ($inString) {
                if ($char === $stringChar && ($i === 0 || $afterClass[$i - 1] !== '\\')) {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $stringChar = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }
        }

        // depth === 1 means we're directly inside the class body (not in a method)
        return $depth === 1;
    }
}
