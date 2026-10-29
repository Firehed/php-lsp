<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Parser\NodeAtPosition;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Resolution\Scope;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * The one place that reads a document's structure at a position. It reads the
 * document's text for punctuation and words the structure does not record:
 * the word written before the position, whether only whitespace and comments
 * separate the position from a slot nothing is written in yet, the
 * punctuation of a declaration's header, and whether a call's closing
 * parenthesis is written.
 *
 * @phpstan-type MemberAccess Expr\MethodCall|Expr\NullsafeMethodCall|Expr\PropertyFetch
 *     |Expr\NullsafePropertyFetch|Expr\StaticCall|Expr\StaticPropertyFetch|Expr\ClassConstFetch
 */
final class PositionLocator implements PositionLocatorInterface
{
    private const array NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    private const array MODIFIERS = [
        T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_VAR, T_FINAL, T_ABSTRACT,
        T_PUBLIC_SET, T_PROTECTED_SET, T_PRIVATE_SET,
    ];

    public function __construct(
        private readonly SyntaxSourceInterface $syntax,
    ) {
    }

    public function locate(TextDocument $document, ByteOffset $offset): Located
    {
        $parsed = $this->syntax->parse($document);
        $code = $document->getContent();
        $at = $offset->value;

        $found = self::nodeAt($parsed->tree, $at);
        $node = self::isWord($found) ? $found : (self::emptySlotNear($found, $parsed->tree, $code, $at) ?? $found);
        preg_match('/\$?[\w\\\\\x80-\xff]*$/', self::text($code, max(0, $at - 256), $at), $word);
        $prefix = $word[0] ?? '';
        [$slot, $access] = match (true) {
            self::inComment($found, $parsed->tree, $at) => [Slot::NotCode, null],
            $node instanceof FunctionLike => [self::headerSlot($node, $code, $at, $prefix) ?? Slot::Expression, null],
            $node instanceof Stmt\ClassLike => [self::memberHeaderSlot($node, $code, $at, $prefix), null],
            default => self::classify($node, $at),
        };

        $written = $node !== null && self::isWord($node) ? $node : null;

        $call = null;
        for ($n = $node; $n instanceof Node && !$n instanceof FunctionLike; $n = $n->getAttribute('parent')) {
            if (self::isCall($n) && self::inArguments($n, $code, $at)) {
                $call = self::callSite($n, $at);
                break;
            }
        }

        return new Located(
            $parsed,
            $offset,
            $slot,
            $prefix,
            $written,
            $access,
            Scope::at($node, $parsed->tree),
            $call,
        );
    }

    /**
     * The node at a position: a word the position is in or right after, else
     * the deeper of the nodes holding the position and the byte before it.
     *
     * @param array<Stmt> $tree
     */
    private static function nodeAt(array $tree, int $at): ?Node
    {
        $finder = new NodeAtPosition();
        $here = $finder->find($tree, $at);
        $before = $at > 0 ? $finder->find($tree, $at - 1) : null;
        foreach ([$here, $before] as $candidate) {
            if ($candidate !== null && self::isWord($candidate)) {
                return $candidate;
            }
        }
        if ($before !== null && ($here === null || self::isWithin($before, $here))) {
            return $before;
        }
        return $here;
    }

    /**
     * A node for code not written yet, in the statement that holds the
     * position, that only whitespace and comments separate from it: the slot
     * the position is in when nothing is typed there.
     *
     * @param array<Stmt> $tree
     */
    private static function emptySlotNear(?Node $found, array $tree, string $code, int $at): ?Node
    {
        $statement = $found;
        while ($statement !== null && !$statement instanceof Stmt) {
            $parent = $statement->getAttribute('parent');
            $statement = $parent instanceof Node ? $parent : null;
        }
        $candidates = (new NodeFinder())->find(
            $statement === null ? $tree : [$statement],
            static fn (Node $node): bool => !ParsedDocument::isWritten($node)
                && ($node instanceof Expr || $node instanceof Name || $node instanceof Identifier),
        );
        $nearest = null;
        $distance = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $position = $candidate->getStartFilePos();
            [$from, $to] = $position <= $at ? [$position, $at] : [$at, $position];
            if ($to - $from < $distance && self::onlyIgnorable(self::text($code, $from, $to))) {
                $nearest = $candidate;
                $distance = $to - $from;
            }
        }
        return $nearest;
    }

    /**
     * Whether the position is inside a comment. A comment belongs to the node
     * after it, so it is among those of the nodes within what holds the
     * position. A line comment runs to the end of its line.
     *
     * @param array<Stmt> $tree
     */
    private static function inComment(?Node $container, array $tree, int $at): bool
    {
        $owner = (new NodeFinder())->findFirst(
            $container === null ? $tree : [$container],
            static function (Node $node) use ($at): bool {
                foreach ($node->getComments() as $comment) {
                    $lineComment = !str_starts_with($comment->getText(), '/*');
                    $end = $comment->getEndFilePos() + ($lineComment ? 1 : 0);
                    if ($comment->getStartFilePos() < $at && $at <= $end) {
                        return true;
                    }
                }
                return false;
            },
        );
        return $owner !== null;
    }

    /**
     * The slot of a position in a function's header that the structure holds
     * nothing for, read from the header's punctuation: a parameter's type, the
     * return type, or a variable a closure captures. Null in the body, or
     * where none of those fits.
     */
    private static function headerSlot(FunctionLike $function, string $code, int $at, string $prefix): ?Slot
    {
        $depth = 0;
        $parametersClosed = false;
        $usesAt = null;
        $last = null;
        foreach (self::punctuation($code, $function->getStartFilePos(), $at, $prefix) as $token) {
            if ($token->is('(')) {
                $depth++;
                $usesAt ??= $last?->is(T_USE) === true ? $depth : null;
            } elseif ($token->is(')')) {
                $usesAt = $usesAt === $depth ? null : $usesAt;
                $depth--;
                $parametersClosed = $parametersClosed || $depth === 0;
            } elseif ($token->is('{') && $depth === 0) {
                return null;
            }
            $last = $token;
        }
        return match (true) {
            // At the header's start, or in its first keyword: before the function.
            $last === null => null,
            $last->is(T_USE), $usesAt !== null && $last->is(['(', ',']) => Slot::Variable,
            $parametersClosed && $last->is([':', '?', '|', '&', '(', T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG])
                => Slot::ReturnType,
            !$parametersClosed && $depth >= 1
                && $last->is(['(', ',', '?', '|', '&', T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG])
                => Slot::ParameterType,
            default => null,
        };
    }

    /**
     * The slot of a position in a class body that the structure holds
     * nothing for: a property's type, where the member's modifiers are written
     * and the type it has so far waits for another part.
     */
    private static function memberHeaderSlot(Stmt\ClassLike $class, string $code, int $at, string $prefix): Slot
    {
        $modified = false;
        $last = null;
        foreach (self::punctuation($code, $class->getStartFilePos(), $at, $prefix) as $token) {
            if ($token->is([';', '{', '}'])) {
                $modified = false;
            } elseif ($token->is(self::MODIFIERS)) {
                $modified = true;
            }
            $last = $token;
        }
        return $modified && $last->is(['?', '|', '&', '(', T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG])
            ? Slot::PropertyType
            : Slot::ClassMember;
    }

    /**
     * The code tokens from a position to another, less the word being typed
     * there: that word is in the slot the tokens before it open.
     *
     * @return list<\PhpToken>
     */
    private static function punctuation(string $code, int $from, int $at, string $prefix): array
    {
        $tokens = array_values(array_filter(
            array_slice(\PhpToken::tokenize('<?php ' . self::text($code, $from, $at)), 1),
            static fn (\PhpToken $token): bool => !$token->isIgnorable(),
        ));
        if ($prefix !== '' && $tokens !== [] && end($tokens)->is(self::NAMES)) {
            array_pop($tokens);
        }
        return $tokens;
    }

    /**
     * @return array{Slot, MemberAccess|null}
     */
    private static function classify(?Node $node, int $at): array
    {
        $parent = $node?->getAttribute('parent');
        return match (true) {
            $node === null => [Slot::Expression, null],
            $node instanceof Scalar\String_ && $at <= $node->getEndFilePos() => [
                in_array(
                    $node->getAttribute('kind'),
                    [Scalar\String_::KIND_SINGLE_QUOTED, Scalar\String_::KIND_NOWDOC],
                    true,
                )
                    ? Slot::NotCode
                    : Slot::InterpolatedString,
                null,
            ],
            $node instanceof Node\InterpolatedStringPart, $node instanceof Scalar\InterpolatedString
                => [Slot::InterpolatedString, null],
            $node instanceof Stmt\InlineHTML => [Slot::NotCode, null],
            $node instanceof Expr\Variable => [
                $parent instanceof Node\Param && $parent->var === $node ? Slot::DeclaredName : Slot::Variable,
                null,
            ],
            ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall
                || $node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch)
                && $at > $node->var->getEndFilePos() && $at <= $node->name->getStartFilePos()
                => [Slot::Member, $node],
            ($node instanceof Expr\StaticCall || $node instanceof Expr\ClassConstFetch
                || $node instanceof Expr\StaticPropertyFetch)
                && $at > $node->class->getEndFilePos() && $at <= $node->name->getStartFilePos()
                => [Slot::StaticMember, $node],
            ($node instanceof Stmt\Property || $node instanceof Stmt\ClassConst)
                && $at <= self::firstWrittenPart($node) => [self::memberStartSlot($node, $at), null],
            $node instanceof Identifier, $node instanceof Name, $node instanceof Expr\Error
                => self::classifyName($node, $parent),
            default => [Slot::Expression, null],
        };
    }

    /**
     * @return array{Slot, MemberAccess|null}
     */
    private static function classifyName(Identifier|Name|Expr\Error $name, mixed $parent): array
    {
        if (
            ($parent instanceof Expr\MethodCall || $parent instanceof Expr\NullsafeMethodCall
                || $parent instanceof Expr\PropertyFetch || $parent instanceof Expr\NullsafePropertyFetch)
            && $parent->name === $name
        ) {
            return [Slot::Member, $parent];
        }
        if (
            ($parent instanceof Expr\StaticCall || $parent instanceof Expr\ClassConstFetch
                || $parent instanceof Expr\StaticPropertyFetch)
        ) {
            if ($parent->name === $name) {
                return [Slot::StaticMember, $parent];
            }
            return [Slot::StaticReceiver, null];
        }
        if ($parent instanceof Arg && $parent->name === $name) {
            return [Slot::ArgumentName, null];
        }
        if ($parent instanceof Expr\Variable) {
            return [Slot::Variable, null];
        }
        // A name written where a parameter's variable belongs is its type, being typed.
        if ($parent instanceof Node\Param && $parent->var === $name) {
            return [Slot::ParameterType, null];
        }
        if (
            ($parent instanceof Stmt\ClassMethod || $parent instanceof Node\PropertyItem)
            && $parent->name === $name
            && !ParsedDocument::isWritten($name)
        ) {
            return [Slot::ClassMember, null];
        }
        if (
            ($parent instanceof Stmt\ClassMethod || $parent instanceof Stmt\Function_
                || $parent instanceof Stmt\ClassLike || $parent instanceof Node\Const_
                || $parent instanceof Stmt\EnumCase || $parent instanceof Node\PropertyItem)
            && $parent->name === $name
        ) {
            return [Slot::DeclaredName, null];
        }
        $slot = match (true) {
            $parent instanceof Expr\FuncCall => Slot::CalledName,
            $parent instanceof Expr\New_ => Slot::New,
            $parent instanceof Expr\Instanceof_ => Slot::Instanceof,
            $parent instanceof Stmt\Class_ && $parent->extends === $name => Slot::ClassExtends,
            $parent instanceof Stmt\Class_,
            $parent instanceof Stmt\Enum_ && in_array($name, $parent->implements, true) => Slot::Implements,
            $parent instanceof Stmt\Interface_ => Slot::InterfaceExtends,
            $parent instanceof Stmt\Catch_ => Slot::Catch,
            $parent instanceof Attribute => Slot::Attribute,
            $parent instanceof Stmt\TraitUse => Slot::TraitUse,
            $parent instanceof Node\UseItem => Slot::Import,
            default => self::typeSlot($name),
        };
        return [$slot, null];
    }

    /**
     * The slot of a type, by the declaration it types; a part of a union,
     * intersection, or nullable type types what the whole does.
     */
    private static function typeSlot(Node $type): Slot
    {
        $whole = $type;
        $parent = $whole->getAttribute('parent');
        while (
            $parent instanceof Node\NullableType || $parent instanceof Node\UnionType
            || $parent instanceof Node\IntersectionType
        ) {
            $whole = $parent;
            $parent = $whole->getAttribute('parent');
        }
        return match (true) {
            // A bare word right after a property's modifiers may yet be another modifier.
            $parent instanceof Stmt\Property && $parent->type === $type && $parent->flags !== 0
                => Slot::AfterModifiers,
            $parent instanceof Node\Param && $parent->type === $whole => Slot::ParameterType,
            $parent instanceof FunctionLike && $parent->getReturnType() === $whole => Slot::ReturnType,
            ($parent instanceof Stmt\Property || $parent instanceof Stmt\ClassConst) && $parent->type === $whole
                => Slot::PropertyType,
            default => Slot::Expression,
        };
    }

    /**
     * A position before what a property or constant declares: past its
     * modifiers, when it has any, or where a member starts.
     */
    private static function memberStartSlot(Stmt\Property|Stmt\ClassConst $member, int $at): Slot
    {
        return $member->flags !== 0 && $at > $member->getStartFilePos() ? Slot::AfterModifiers : Slot::ClassMember;
    }

    /**
     * Where a property or constant declaration's type, or else its first
     * name, starts: what follows its modifiers.
     */
    private static function firstWrittenPart(Stmt\Property|Stmt\ClassConst $member): int
    {
        $first = $member->type ?? ($member instanceof Stmt\Property ? $member->props[0] : $member->consts[0]);
        return $first->getStartFilePos();
    }

    private static function isWord(?Node $node): bool
    {
        return ($node instanceof Identifier || $node instanceof Name
            || ($node instanceof Expr\Variable && is_string($node->name)))
            && ParsedDocument::isWritten($node);
    }

    private static function isWithin(Node $node, Node $ancestor): bool
    {
        for ($n = $node->getAttribute('parent'); $n instanceof Node; $n = $n->getAttribute('parent')) {
            if ($n === $ancestor) {
                return true;
            }
        }
        return false;
    }

    /**
     * @phpstan-assert-if-true Expr\FuncCall|Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall
     *     |Expr\New_|Attribute $node
     */
    private static function isCall(Node $node): bool
    {
        return $node instanceof Expr\FuncCall
            || $node instanceof Expr\MethodCall
            || $node instanceof Expr\NullsafeMethodCall
            || $node instanceof Expr\StaticCall
            || $node instanceof Expr\New_
            || $node instanceof Attribute;
    }

    /**
     * Whether a position is in a call's argument list: past what it calls,
     * and before its closing parenthesis when that is written. A call with
     * nothing written to call has no argument list yet. An anonymous class
     * holds its body too, so only its arguments' own span is the list.
     */
    private static function inArguments(
        Expr\FuncCall|Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall|Expr\New_|Attribute $call,
        string $code,
        int $at,
    ): bool {
        $callee = $call instanceof Expr\New_ ? $call->class : $call->name;
        if ($callee instanceof Stmt\Class_) {
            return $call->args !== []
                && $at >= $call->args[0]->getStartFilePos()
                && $at <= $call->args[count($call->args) - 1]->getEndFilePos() + 1;
        }
        $closed = ($code[$call->getEndFilePos()] ?? '') === ')';
        return ParsedDocument::isWritten($callee)
            && $at > $callee->getEndFilePos() + 1
            && (!$closed || $at <= $call->getEndFilePos());
    }

    private static function callSite(
        Expr\FuncCall|Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall|Expr\New_|Attribute $call,
        int $at,
    ): CallSite {
        $separators = ParsedDocument::argumentSeparatorsOf($call) ?? [];
        $argumentIndex = count(array_filter($separators, static fn (int $position): bool => $position < $at));
        $usedNames = [];
        $positionalCount = 0;
        $sawNamed = false;
        foreach ($call->args as $i => $arg) {
            if ($arg instanceof Arg && $arg->name !== null) {
                $usedNames[] = $arg->name->name;
                $sawNamed = true;
            } elseif (!$sawNamed && $i < $argumentIndex) {
                $positionalCount++;
            }
        }
        return new CallSite($call, $argumentIndex, $usedNames, $positionalCount);
    }

    /**
     * Whether text holds only whitespace and comments.
     */
    private static function onlyIgnorable(string $text): bool
    {
        foreach (array_slice(\PhpToken::tokenize('<?php ' . $text), 1) as $token) {
            if (!$token->isIgnorable()) {
                return false;
            }
        }
        return true;
    }

    private static function text(string $code, int $from, int $to): string
    {
        return $to > $from ? substr($code, $from, $to - $from) : '';
    }
}
