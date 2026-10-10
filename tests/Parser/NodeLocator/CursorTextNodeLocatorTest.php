<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\NodeLocator;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\NodeLocator\CursorTextNodeLocator;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\DescribesSyntaxTreesTrait;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CursorTextNodeLocator::class)]
class CursorTextNodeLocatorTest extends TestCase
{
    use DescribesSyntaxTreesTrait;
    use LoadsFixturesTrait;

    public function testNodeAtRejectsAnOffsetOutsideTheDocument(): void
    {
        $source = new CursorTextNodeLocator();
        $document = new TextDocument('file:///doc.php', 'php', 1, '<?php $this->');
        $parsed = new ParsedDocument($document, []);

        self::assertNull($source->nodeAt($parsed, -1), 'a negative offset falls outside the document');
        self::assertNull(
            $source->nodeAt($parsed, strlen($document->getContent()) + 1),
            'an offset past the last byte falls outside the document',
        );
    }

    public function testAFullyQualifiedStaticAccessBecomesAFullyQualifiedReceiver(): void
    {
        $source = new CursorTextNodeLocator();
        $content = "<?php\n\\Foo::";
        $document = new TextDocument('file:///fq.php', 'php', 1, $content);
        $fooOffset = strpos($content, 'Foo');
        self::assertNotFalse($fooOffset);
        $offset = $fooOffset + 1;

        $node = $source->nodeAt(new ParsedDocument($document, []), $offset);
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
        $source = new CursorTextNodeLocator();
        $content = '<?php if (';
        $document = new TextDocument('file:///kw.php', 'php', 1, $content);

        $node = $source->nodeAt(new ParsedDocument($document, []), strlen($content));

        self::assertNull($node, 'keywords in NON_FUNCTION_KEYWORD_PATTERN are not treated as function calls');
    }

    public function testUnclosedParenScanStopsAtStatementBoundary(): void
    {
        // The `;` between `foo(` and the cursor ends the scan: the paren
        // belongs to a prior statement, so nothing is unclosed at the cursor.
        $source = new CursorTextNodeLocator();
        $content = "<?php\nfoo(); \$this->";
        $document = new TextDocument('file:///stmt.php', 'php', 1, $content);
        $offset = strlen($content);

        $node = $source->nodeAt(new ParsedDocument($document, []), $offset);

        // Member access is still synthesized on its own; there's no enclosing call.
        $call = self::enclosingCall($node);
        self::assertNull($call, 'the paren before the `;` is not the cursor\'s enclosing call');
    }

    public function testUnclosedParenScanStopsAtOpenBrace(): void
    {
        $source = new CursorTextNodeLocator();
        $content = "<?php\nclass X { public function m() { foo";
        $document = new TextDocument('file:///brace.php', 'php', 1, $content);

        $node = $source->nodeAt(new ParsedDocument($document, []), strlen($content));

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

    /**
     * @return array<string, array{string, class-string, string}>
     */
    public static function typedValues(): array
    {
        return [
            'a bare word' => ['bare_word_in_call', ConstFetch::class, 'cou'],
            'a variable' => ['variable_in_call', Variable::class, 'va'],
        ];
    }

    /**
     * @param class-string $valueClass
     */
    #[DataProvider('typedValues')]
    public function testTheArgumentBeingTypedHoldsWhatWasTyped(string $marker, string $valueClass, string $text): void
    {
        $fixture = 'src/Completion/EditingNamedArg.php';
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);

        $offset = $this->markerOffset($content, $marker);
        $call = self::enclosingCall((new CursorTextNodeLocator())->nodeAt(new ParsedDocument($document, []), $offset));

        self::assertNotNull($call, 'the cursor is inside an unclosed call');
        $value = $call->args[0]->value ?? null;
        self::assertInstanceOf($valueClass, $value, 'the typed argument says whether a name could still follow');
        $typed = match (true) {
            $value instanceof ConstFetch => $value->name->toString(),
            $value instanceof Variable => $value->name,
            default => null,
        };
        self::assertSame($text, $typed, 'the value holds the text typed so far');
    }

    public function testRecordsTheCommasBetweenTheCallsOwnArguments(): void
    {
        $fixture = 'src/Completion/EditingNamedArg.php';
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $offset = $this->markerOffset($content, 'nested_brackets');

        $call = self::enclosingCall((new CursorTextNodeLocator())->nodeAt(new ParsedDocument($document, []), $offset));

        self::assertNotNull($call, 'the cursor is inside an unclosed call');
        self::assertSame(
            [$offset - 2],
            $call->getAttribute(ParsedDocument::ARGUMENT_SEPARATORS),
            'only the comma after the nested call is the call\'s own',
        );
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

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function resolvedNames(): array
    {
        $fixture = 'src/Resolution/CursorTextResolution.php';
        return [
            'aliased import, static call' => [$fixture, 'aliased_static', 'FullyQualified(Fixtures\Domain\User)'],
            'group import, static call' => [$fixture, 'group_static', 'FullyQualified(Fixtures\Enum\Priority)'],
            'aliased import, new' => [$fixture, 'aliased_new', 'FullyQualified(Fixtures\Domain\User)'],
            'aliased import, attribute' => [$fixture, 'attribute', 'FullyQualified(Fixtures\Domain\User)'],
            'unqualified function in a namespace' => [
                $fixture,
                'namespaced_function',
                'Name(helper) ns:Fixtures\Resolution\helper',
            ],
            'imported function' => [$fixture, 'imported_function', 'FullyQualified(Fixtures\Utility\formatName)'],
            'qualified class through an import' => [
                $fixture,
                'qualified_static',
                'FullyQualified(Fixtures\Domain\User)',
            ],
            'qualified function through an import' => [
                $fixture,
                'qualified_function',
                'FullyQualified(Fixtures\Domain\helper)',
            ],
            'namespace-relative class' => [
                $fixture,
                'relative_static',
                'FullyQualified(Fixtures\Resolution\CursorTextResolution)',
            ],
            'namespace-relative function' => [
                $fixture,
                'relative_function',
                'FullyQualified(Fixtures\Resolution\helper)',
            ],
            'import outside any namespace' => [
                'SignatureHelp.php',
                'constructor',
                'FullyQualified(Fixtures\Domain\User)',
            ],
        ];
    }

    #[DataProvider('resolvedNames')]
    public function testNamesResolveAgainstTheImportsInEffect(string $fixture, string $marker, string $expected): void
    {
        self::assertSame(
            $expected,
            self::describeName($this->callAtMarker($fixture, $marker)),
            'the name resolves the way the parser resolves it',
        );
    }

    public function testTheSynthesizedNodeIsLinkedUnderItsEnclosingMethod(): void
    {
        $call = $this->callAtMarker('src/Resolution/CursorTextResolution.php', 'aliased_static');

        $enclosing = self::ancestorOf($call, Stmt\ClassMethod::class);
        self::assertNotNull($enclosing, 'parent links reach the enclosing method');
        self::assertSame('run', $enclosing->name->toString());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function incompleteNames(): array
    {
        return [
            'namespace prefix alone, called' => ['relative_prefix_call'],
            'namespace prefix alone, instantiated' => ['relative_prefix_new'],
            'namespace prefix alone, static call' => ['relative_prefix_static'],
            'separator alone, static call' => ['separator_static'],
        ];
    }

    #[DataProvider('incompleteNames')]
    public function testAnIncompleteNameSynthesizesNoCall(string $marker): void
    {
        $parsed = $this->parsedFixture('TopLevel/incomplete_names.php');

        self::assertNull(
            self::enclosingCall($this->nodeAtMarker($parsed, $marker)),
            'a name with nothing after its prefix names no callable',
        );
    }

    public function testAChainedAccessKeepsEachSegment(): void
    {
        $parsed = $this->parsedFixture('src/IncompleteCode/ChainedAccess.php');

        $fetch = $this->nodeAtMarker($parsed, 'chained_in_if')?->getAttribute('parent');

        self::assertInstanceOf(PropertyFetch::class, $fetch, 'the access being typed is a property fetch');
        $receiver = $fetch->var;
        self::assertInstanceOf(PropertyFetch::class, $receiver, 'its receiver is the earlier segment');
        self::assertInstanceOf(Node\Identifier::class, $receiver->name);
        self::assertSame('user', $receiver->name->toString(), 'the earlier segment keeps its name');
        self::assertInstanceOf(Variable::class, $receiver->var, 'the chain starts at the variable');
        self::assertSame('this', $receiver->var->name);
    }

    public function testAnAttributeSitsInAnAttributeGroupUnderItsDeclaration(): void
    {
        $attribute = $this->callAtMarker('src/Resolution/CursorTextResolution.php', 'attribute');

        $group = $attribute->getAttribute('parent');
        self::assertInstanceOf(Node\AttributeGroup::class, $group, 'an attribute belongs to an attribute group');
        self::assertSame(
            $attribute->getStartFilePos(),
            $group->getStartFilePos(),
            'the group carries the position of the attribute it holds',
        );
        self::assertInstanceOf(
            Stmt\ClassMethod::class,
            $group->getAttribute('parent'),
            'the group belongs to the declaration it decorates',
        );
    }

    public function testACallTypedAtNamespaceLevelHangsOffTheNamespace(): void
    {
        $call = $this->callAtMarker('TopLevel/trailing_call_in_namespace.php', 'trailing_call');

        $statement = $call->getAttribute('parent');
        self::assertInstanceOf(Stmt\Expression::class, $statement, 'a call is held by an expression statement');
        self::assertSame(
            $call->getStartFilePos(),
            $statement->getStartFilePos(),
            'the statement carries the position of the call it holds',
        );
        self::assertInstanceOf(
            Stmt\Namespace_::class,
            $statement->getAttribute('parent'),
            'with no enclosing statement, the namespace holds it',
        );
    }

    public function testTheDocumentTreeIsLeftUntouched(): void
    {
        $parsed = $this->parsedFixture('src/Resolution/CursorTextResolution.php');
        $namespace = $parsed->tree[1];
        self::assertInstanceOf(Stmt\Namespace_::class, $namespace);
        $import = $namespace->stmts[0];
        self::assertInstanceOf(Stmt\Use_::class, $import);

        $this->nodeAtMarker($parsed, 'aliased_static');

        self::assertSame($namespace, $import->getAttribute('parent'), 'the document\'s imports keep their own parents');
    }

    private function parsedFixture(string $fixture): ParsedDocument
    {
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $this->loadFixture($fixture));

        return (new PhpParserSyntaxSource(new TreeAnnotator()))->parse($document);
    }

    private function nodeAtMarker(ParsedDocument $parsed, string $marker): ?Node
    {
        return (new CursorTextNodeLocator())->nodeAt(
            $parsed,
            $this->markerOffset($parsed->document->getContent(), $marker),
        );
    }

    private function callAtMarker(string $fixture, string $marker): Node
    {
        $call = self::enclosingCall($this->nodeAtMarker($this->parsedFixture($fixture), $marker));
        self::assertNotNull($call, 'a call is synthesized at the marker');

        return $call;
    }

    private static function synthesizeCallAtCursor(string $content): ?Node
    {
        $source = new CursorTextNodeLocator();
        $document = new TextDocument('file:///call.php', 'php', 1, $content);
        return self::enclosingCall($source->nodeAt(new ParsedDocument($document, []), strlen($content)));
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
