<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\CursorTextSyntaxSource;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name\FullyQualified;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CursorTextSyntaxSource::class)]
class CursorTextSyntaxSourceTest extends TestCase
{
    public function testParseYieldsAnEmptyTree(): void
    {
        $source = new CursorTextSyntaxSource();
        $document = new TextDocument('file:///empty.php', 'php', 1, '<?php $x = 1;');

        self::assertSame(
            [],
            $source->parse($document),
            'parse() carries no tree; the cursor-text source is nodeAt-only',
        );
    }

    public function testNodeAtRejectsAnOffsetOutsideTheDocument(): void
    {
        $source = new CursorTextSyntaxSource();
        $document = new TextDocument('file:///doc.php', 'php', 1, '<?php $this->');

        self::assertNull($source->nodeAt([], $document, -1), 'a negative offset falls outside the document');
        self::assertNull(
            $source->nodeAt([], $document, strlen($document->getContent()) + 1),
            'an offset past the last byte falls outside the document',
        );
    }

    public function testAFullyQualifiedStaticAccessBecomesAFullyQualifiedReceiver(): void
    {
        $source = new CursorTextSyntaxSource();
        $content = "<?php\n\\Foo::";
        $document = new TextDocument('file:///fq.php', 'php', 1, $content);
        $fooOffset = strpos($content, 'Foo');
        self::assertNotFalse($fooOffset);
        $offset = $fooOffset + 1;

        $node = $source->nodeAt([], $document, $offset);
        self::assertInstanceOf(
            FullyQualified::class,
            $node,
            'a leading-separator name keeps the fully-qualified shape php-parser emits',
        );
        self::assertSame('Foo', $node->toString(), 'the leading separator is dropped from the stored name');
        $parent = $node->getAttribute('parent');
        self::assertInstanceOf(
            StaticPropertyFetch::class,
            $parent,
            'the FullyQualified receiver is attached to a StaticPropertyFetch',
        );
    }

    public function testSynthesizesFuncCallAtUnclosedParen(): void
    {
        $call = self::synthesizeCallAtCursor("<?php\nstrlen(");

        self::assertInstanceOf(FuncCall::class, $call, 'a bare `name(` becomes a FuncCall');
        self::assertSame('strlen', self::funcCallName($call));
        self::assertSame([], $call->args, 'no args before the cursor means an empty args list');
    }

    public function testSynthesizesStaticCallAtUnclosedParen(): void
    {
        $call = self::synthesizeCallAtCursor("<?php\nFoo::bar(");

        self::assertInstanceOf(StaticCall::class, $call);
        self::assertSame('Foo', self::staticCallClass($call));
        self::assertSame('bar', self::staticCallName($call));
    }

    public function testSynthesizesMethodCallAtUnclosedParen(): void
    {
        $call = self::synthesizeCallAtCursor('<?php $x->method(');

        self::assertInstanceOf(MethodCall::class, $call);
        self::assertSame('x', self::methodCallVar($call));
        self::assertSame('method', self::methodCallName($call));
    }

    public function testSynthesizesNullsafeMethodCallAtUnclosedParen(): void
    {
        $call = self::synthesizeCallAtCursor('<?php $x?->method(');

        self::assertInstanceOf(NullsafeMethodCall::class, $call);
        self::assertSame('x', self::methodCallVar($call));
        self::assertSame('method', self::methodCallName($call));
    }

    public function testSynthesizesNewAtUnclosedParen(): void
    {
        $call = self::synthesizeCallAtCursor('<?php new Foo(');

        self::assertInstanceOf(New_::class, $call);
        $class = $call->class;
        self::assertInstanceOf(Node\Name::class, $class);
        self::assertSame('Foo', $class->toString());
    }

    public function testSynthesizesFullyQualifiedNew(): void
    {
        $call = self::synthesizeCallAtCursor('<?php new \\Foo(');

        self::assertInstanceOf(New_::class, $call);
        $class = $call->class;
        self::assertInstanceOf(
            FullyQualified::class,
            $class,
            'a leading-separator new-expression keeps the FullyQualified shape',
        );
    }

    public function testSynthesizesAttributeAtUnclosedParen(): void
    {
        $call = self::synthesizeCallAtCursor('<?php #[Route(');

        self::assertInstanceOf(Attribute::class, $call);
        self::assertSame('Route', $call->name->toString());
    }

    public function testKeywordBeforeParenDoesNotSynthesizeACall(): void
    {
        // `if (` is a control structure, not a call. `nodeAt` returns null,
        // so no consumer walks up to a FuncCall it wasn't invoking.
        $source = new CursorTextSyntaxSource();
        $content = '<?php if (';
        $document = new TextDocument('file:///kw.php', 'php', 1, $content);

        $node = $source->nodeAt([], $document, strlen($content));

        self::assertNull($node, 'keywords in NON_FUNCTION_KEYWORD_PATTERN are not treated as function calls');
    }

    public function testUnclosedParenScanStopsAtStatementBoundary(): void
    {
        // The `;` between `foo(` and the cursor ends the scan: the paren
        // belongs to a prior statement, so nothing is unclosed at the cursor.
        $source = new CursorTextSyntaxSource();
        $content = "<?php\nfoo(); \$this->";
        $document = new TextDocument('file:///stmt.php', 'php', 1, $content);
        $offset = strlen($content);

        $node = $source->nodeAt([], $document, $offset);

        // Member access is still synthesized on its own; there's no enclosing call.
        $call = self::resolveToCall($node);
        self::assertNull($call, 'the paren before the `;` is not the cursor\'s enclosing call');
    }

    public function testUnclosedParenScanStopsAtOpenBrace(): void
    {
        $source = new CursorTextSyntaxSource();
        $content = "<?php\nclass X { public function m() { foo";
        $document = new TextDocument('file:///brace.php', 'php', 1, $content);

        $node = $source->nodeAt([], $document, strlen($content));

        self::assertNull($node, 'no unclosed paren once the scan hits a `{`');
    }

    public function testUnclosedParenScanHandlesNestedParens(): void
    {
        // The inner `bar()` opens and closes at depth 1; only the outer `foo(`
        // remains unclosed at the cursor. Exercises both depth++ and depth--.
        $call = self::synthesizeCallAtCursor("<?php\nfoo(bar(), ");

        self::assertInstanceOf(FuncCall::class, $call);
        self::assertSame('foo', self::funcCallName($call));
    }

    public function testMemberAccessNestsInsideTheEnclosingCall(): void
    {
        $call = self::synthesizeCallAtCursor("<?php\nfoo(\$x->");

        self::assertInstanceOf(FuncCall::class, $call);
        self::assertCount(1, $call->args, 'the member-access at the cursor is wrapped as the trailing arg');
        $arg = $call->args[0];
        self::assertInstanceOf(Arg::class, $arg);
        self::assertInstanceOf(
            PropertyFetch::class,
            $arg->value,
            'the arg\'s value is the synthesized member-access node, not a placeholder',
        );
    }

    public function testMultiArgCallCountsCompletedSegments(): void
    {
        // Two commas at depth zero close two args. The trailing empty segment
        // is not built.
        $call = self::synthesizeCallAtCursor("<?php\nfoo(1, 2, ");

        self::assertInstanceOf(FuncCall::class, $call);
        self::assertCount(2, $call->args, 'each comma at depth zero closes an arg');
    }

    public function testNamedArgInTrailingSegmentIsCaptured(): void
    {
        $call = self::synthesizeCallAtCursor("<?php\nfoo(name: ");

        self::assertInstanceOf(FuncCall::class, $call);
        self::assertCount(1, $call->args, 'a `name:` prefix on the trailing segment produces an Arg');
        $arg = $call->args[0];
        self::assertInstanceOf(Arg::class, $arg);
        self::assertNotNull($arg->name);
        self::assertSame('name', $arg->name->name);
    }

    public function testNamedArgInCompletedSegment(): void
    {
        // `name: 1, ` closes one named arg; the trailing empty segment is not
        // built.
        $call = self::synthesizeCallAtCursor("<?php\nfoo(name: 1, ");

        self::assertInstanceOf(FuncCall::class, $call);
        self::assertCount(1, $call->args);
        $arg = $call->args[0];
        self::assertInstanceOf(Arg::class, $arg);
        self::assertNotNull($arg->name);
        self::assertSame('name', $arg->name->name);
    }

    public function testBareTrailingCommaDoesNotProduceAnArg(): void
    {
        // A completed empty segment before the cursor is dropped: no name, no
        // cursor content, no member.
        $call = self::synthesizeCallAtCursor("<?php\nfoo(, ");

        self::assertInstanceOf(FuncCall::class, $call);
        self::assertSame(
            [],
            $call->args,
            'a bare `,` before the cursor does not manufacture a phantom positional arg',
        );
    }

    public function testTrailingArgWithPlaceholderValue(): void
    {
        // The trailing segment carries a named prefix but no `$memberInside`;
        // buildArg fills in a placeholder Variable for the value slot.
        $call = self::synthesizeCallAtCursor("<?php\nfoo(name: ");

        self::assertInstanceOf(FuncCall::class, $call);
        $arg = $call->args[0];
        self::assertInstanceOf(Arg::class, $arg);
        self::assertInstanceOf(
            Variable::class,
            $arg->value,
            'the value is a placeholder Variable when the arg carries no expression',
        );
    }

    private static function synthesizeCallAtCursor(string $content): ?Node
    {
        $source = new CursorTextSyntaxSource();
        $document = new TextDocument('file:///call.php', 'php', 1, $content);
        $node = $source->nodeAt([], $document, strlen($content));
        return self::resolveToCall($node);
    }

    private static function resolveToCall(?Node $node): ?Node
    {
        while ($node !== null && !self::isCall($node)) {
            $parent = $node->getAttribute('parent');
            $node = $parent instanceof Node ? $parent : null;
        }
        return $node;
    }

    private static function isCall(Node $node): bool
    {
        return $node instanceof FuncCall
            || $node instanceof MethodCall
            || $node instanceof NullsafeMethodCall
            || $node instanceof StaticCall
            || $node instanceof New_
            || $node instanceof Attribute;
    }

    private static function funcCallName(FuncCall $call): string
    {
        self::assertInstanceOf(Node\Name::class, $call->name);
        return $call->name->toString();
    }

    private static function staticCallClass(StaticCall $call): string
    {
        self::assertInstanceOf(Node\Name::class, $call->class);
        return $call->class->toString();
    }

    private static function staticCallName(StaticCall $call): string
    {
        self::assertInstanceOf(Node\Identifier::class, $call->name);
        return $call->name->toString();
    }

    private static function methodCallVar(MethodCall|NullsafeMethodCall $call): string
    {
        self::assertInstanceOf(Variable::class, $call->var);
        self::assertIsString($call->var->name);
        return $call->var->name;
    }

    private static function methodCallName(MethodCall|NullsafeMethodCall $call): string
    {
        self::assertInstanceOf(Node\Identifier::class, $call->name);
        return $call->name->toString();
    }
}
