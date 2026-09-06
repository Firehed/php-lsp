<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Parser\SyntaxSource\CursorTextSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\MemoizingSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SkeletonSyntaxSource;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;
use Firehed\PhpLsp\Resolution\CallContextDetector;
use Firehed\PhpLsp\Resolution\NameContextFactory;
use Firehed\PhpLsp\Resolution\Scope;
use Firehed\PhpLsp\Resolution\ScopeFinder;
use Firehed\PhpLsp\Resolution\TextFallbackHelper;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RFC 1 §4.11: Text-derived fallback logic MUST agree with the syntax-tree path
 * on input that parses; that agreement MUST be held by test, not review.
 *
 * These tests verify that on parseable code, the AST-based resolution and the
 * text-based fallback produce identical results for every positional question
 * both paths answer.
 *
 * Known divergences are marked skipped with their owning slice; each skip is
 * removed when that slice lands and the paths agree.
 */
#[CoversNothing]
final class AstTextAgreementTest extends TestCase
{
    use LoadsFixturesTrait;

    private MemoizingSyntaxSource $parser;
    private TextFallbackHelper $textFallback;
    private CallContextDetector $callDetector;
    private CursorTextSyntaxSource $cursorText;

    protected function setUp(): void
    {
        $production = ProductionSyntaxSource::create();
        $this->parser = $production->source;
        $this->textFallback = new TextFallbackHelper();
        $this->callDetector = new CallContextDetector($this->textFallback, $this->parser);
        $this->cursorText = new CursorTextSyntaxSource();
    }

    #[DataProvider('enclosingClassFixtures')]
    public function testEnclosingClassAgreement(string $fixture, int $line, ?string $expected): void
    {
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $ast = $this->parser->parse($document);

        $offset = $document->offsetAt($line, 0);
        $classLike = Scope::atOffset($ast, $offset)->getEnclosingClassLike();
        $astResult = $classLike !== null ? ScopeFinder::getClassLikeName($classLike) : null;
        $textResult = $this->textFallback->findEnclosingClassFromContent($content, $line);

        self::assertSame($expected, $astResult, 'AST path must match expected');
        self::assertSame($expected, $textResult, 'Text path must agree with AST path');
    }

    /**
     * @return array<string, array{string, int, ?string}>
     */
    public static function enclosingClassFixtures(): array
    {
        return [
            'inside class method' => ['src/Domain/User.php', 50, 'Fixtures\Domain\User'],
            'outside any class' => ['src/Domain/User.php', 3, null],
            'inside trait method' => ['src/Traits/HasTimestamps.php', 15, 'Fixtures\Traits\HasTimestamps'],
            'inside interface' => ['src/Domain/Entity.php', 10, 'Fixtures\Domain\Entity'],
            'inside enum' => ['src/Enum/Status.php', 15, 'Fixtures\Enum\Status'],
        ];
    }

    #[DataProvider('nameContextFixtures')]
    public function testNameContextAgreement(string $fixture, int $line): void
    {
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $ast = $this->parser->parse($document);

        $skeleton = new SkeletonSyntaxSource();
        $fromAst = NameContextFactory::fromAst($ast, $line);
        $fromText = NameContextFactory::fromText($document, $line, $skeleton);

        self::assertSame(
            $fromAst->namespace,
            $fromText->namespace,
            'Namespace must agree between AST and text paths',
        );
        self::assertSame(
            $fromAst->classImports,
            $fromText->classImports,
            'Class imports must agree between AST and text paths',
        );
    }

    /**
     * @param class-string<FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute> $expectedNodeClass
     */
    #[DataProvider('callContextFixtures')]
    public function testCallContextAgreement(
        string $fixture,
        string $marker,
        string $expectedNodeClass,
        int $expectedActiveParam,
    ): void {
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $ast = $this->parser->parse($document);

        $offset = $this->markerOffset($content, $marker);
        $line = $this->lineForOffset($content, $offset);

        $astResult = $this->callDetector->fromAst($ast, $document, $offset);
        $textResult = $this->callDetector->fromText($ast, $offset, $content, $line);

        self::assertNotNull($astResult, 'AST path must detect the call');
        self::assertNotNull($textResult, 'Text path must detect the call');

        self::assertInstanceOf(
            $expectedNodeClass,
            $astResult[0],
            'AST path must find the expected node type',
        );
        self::assertSame(
            $astResult[0]::class,
            $textResult[0]::class,
            'Call node type must agree between AST and text paths',
        );
        self::assertSame(
            $expectedActiveParam,
            $astResult[1],
            'AST active parameter must match expected',
        );
        self::assertSame(
            $astResult[1],
            $textResult[1],
            'Active parameter must agree between AST and text paths',
        );
        self::assertSame(
            $astResult[2],
            $textResult[2],
            'Used parameter names must agree between AST and text paths',
        );
        self::assertSame(
            $astResult[3],
            $textResult[3],
            'Positional count must agree between AST and text paths',
        );
    }

    /**
     * @return array<string, array{string, string, class-string, int}>
     */
    public static function callContextFixtures(): array
    {
        return [
            'function call first arg' => [
                'SignatureHelp.php', 'first_param', FuncCall::class, 0,
            ],
            'function call second arg' => [
                'SignatureHelp.php', 'second_param', FuncCall::class, 1,
            ],
            'constructor' => [
                'SignatureHelp.php', 'constructor', New_::class, 0,
            ],
            'static call' => [
                'SignatureHelp.php', 'static_call', StaticCall::class, 0,
            ],
            'builtin function' => [
                'SignatureHelp.php', 'builtin', FuncCall::class, 0,
            ],
            '$this method call' => [
                'src/Domain/User.php', 'sig_this_call', MethodCall::class, 0,
            ],
            'new expression' => [
                'src/Domain/User.php', 'sig_new', New_::class, 0,
            ],
            'builtin strlen' => [
                'src/Domain/User.php', 'sig_builtin_func', FuncCall::class, 0,
            ],
            'named args' => [
                'SignatureHelp.php', 'named_arg', FuncCall::class, 1,
            ],
            'typed param method call' => [
                'SignatureHelp.php', 'typed_param', MethodCall::class, 0,
            ],
            'nullsafe method call' => [
                'SignatureHelp.php', 'nullsafe_param', NullsafeMethodCall::class, 0,
            ],
        ];
    }

    /**
     * On every fixture that parses, the composite (php-parser wins) and the
     * cursor-text source alone must land on a node of the same class carrying
     * the same receiver and name at the cursor. Diverging shapes at the cursor
     * would mean the empty-parse path and the parsed-tree path resolve a member
     * against different receivers — the M×N the cursor-text source exists to
     * prevent (build-manifest step-40, RFC 1 §4.11).
     */
    #[DataProvider('memberAccessFixtures')]
    public function testMemberAccessCursorAgreement(
        string $fixture,
        string $marker,
        string $expectedReceiverKind,
        string $expectedReceiverName,
    ): void {
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $ast = $this->parser->parse($document);

        ['line' => $line, 'character' => $character] = $this->locateCursor($content, $marker);
        $offset = $document->offsetAt($line, $character);
        // Match MemberAccessDetector's cursor semantic: probe the last char
        // before the cursor, so we land on the arrow rather than on the marker.
        $probe = $offset > 0 ? $offset - 1 : 0;

        $compositeNode = $this->parser->nodeAt($ast, $document, $probe);
        $cursorNode = $this->cursorText->nodeAt([], $document, $probe);

        self::assertNotNull($compositeNode, 'composite must find a node at the cursor');
        self::assertNotNull($cursorNode, 'cursor-text source must synthesize a node at the cursor');

        $compositeAccess = self::resolveToAccessNode($compositeNode);
        $cursorAccess = self::resolveToAccessNode($cursorNode);

        self::assertNotNull($compositeAccess, 'composite node must be inside a member-access expression');
        self::assertNotNull($cursorAccess, 'cursor-text node must be inside a member-access expression');

        self::assertSame(
            $expectedReceiverKind,
            self::receiverKind($cursorAccess),
            'cursor-text receiver kind must match expected',
        );
        self::assertSame(
            self::receiverKind($compositeAccess),
            self::receiverKind($cursorAccess),
            'receiver kind must agree between composite and cursor-text source',
        );
        self::assertSame(
            $expectedReceiverName,
            self::receiverName($cursorAccess),
            'cursor-text receiver name must match expected',
        );
        self::assertSame(
            self::receiverName($compositeAccess),
            self::receiverName($cursorAccess),
            'receiver name must agree between composite and cursor-text source',
        );
        self::assertSame(
            self::prefixName($compositeAccess),
            self::prefixName($cursorAccess),
            'member name/prefix must agree between composite and cursor-text source',
        );
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function memberAccessFixtures(): array
    {
        $fixture = 'src/Resolution/MemberAccessAgreement.php';
        return [
            '$this->method' => [
                $fixture,
                'this_method',
                'Variable',
                'this',
            ],
            '$this->property' => [
                $fixture,
                'this_property',
                'Variable',
                'this',
            ],
            'self::method' => [
                $fixture,
                'self_static',
                'Name',
                'self',
            ],
            'parent::method' => [
                $fixture,
                'parent_static',
                'Name',
                'parent',
            ],
            'imported class ::method' => [
                $fixture,
                'class_static',
                'Name',
                'User',
            ],
            'fully qualified class ::method' => [
                $fixture,
                'fq_static',
                'Name',
                'User',
            ],
        ];
    }

    private static function resolveToAccessNode(Node $node): ?Node
    {
        while (
            !($node instanceof MethodCall)
            && !($node instanceof NullsafeMethodCall)
            && !($node instanceof PropertyFetch)
            && !($node instanceof StaticCall)
            && !($node instanceof StaticPropertyFetch)
            && !($node instanceof \PhpParser\Node\Expr\ClassConstFetch)
        ) {
            $parent = $node->getAttribute('parent');
            if (!$parent instanceof Node) {
                return null;
            }
            $node = $parent;
        }
        return $node;
    }

    private static function receiverKind(Node $access): string
    {
        $receiver = self::receiverOf($access);
        if ($receiver === null) {
            return '';
        }
        // FullyQualified is a Name subtype the name resolver may swap in for an
        // imported short name; treat it as Name so a same-family receiver reads
        // the same regardless of which side of the resolver produced it.
        if ($receiver instanceof \PhpParser\Node\Name) {
            return 'Name';
        }
        return self::shortClass($receiver);
    }

    private static function receiverName(Node $access): string
    {
        $receiver = self::receiverOf($access);
        if ($receiver instanceof \PhpParser\Node\Expr\Variable && is_string($receiver->name)) {
            return $receiver->name;
        }
        if ($receiver instanceof \PhpParser\Node\Name) {
            // Php-parser's name resolver rewrites an imported name in place, so
            // an alias reads as its FQN on the composite side and as the short
            // form from the cursor-text source; compare the short tail, which
            // agrees on both sides.
            return $receiver->getLast();
        }
        return '';
    }

    private static function prefixName(Node $access): string
    {
        $name = self::nameOf($access);
        if ($name instanceof \PhpParser\Node\Identifier) {
            return $name->toString();
        }
        return '';
    }

    private static function receiverOf(Node $access): ?Node
    {
        if (
            $access instanceof MethodCall
            || $access instanceof NullsafeMethodCall
            || $access instanceof PropertyFetch
        ) {
            return $access->var;
        }
        if (
            $access instanceof StaticCall
            || $access instanceof StaticPropertyFetch
            || $access instanceof \PhpParser\Node\Expr\ClassConstFetch
        ) {
            return $access->class;
        }
        return null;
    }

    private static function nameOf(Node $access): ?Node
    {
        if (
            $access instanceof MethodCall
            || $access instanceof NullsafeMethodCall
            || $access instanceof PropertyFetch
            || $access instanceof StaticCall
            || $access instanceof StaticPropertyFetch
            || $access instanceof \PhpParser\Node\Expr\ClassConstFetch
        ) {
            return $access->name;
        }
        return null;
    }

    private static function shortClass(Node $node): string
    {
        $class = $node::class;
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }

    private function markerOffset(string $content, string $marker): int
    {
        $pos = strpos($content, '/*|' . $marker . '*/');
        self::assertNotFalse($pos, "Marker {$marker} not found");
        return $pos;
    }

    private function lineForOffset(string $content, int $offset): int
    {
        return substr_count($content, "\n", 0, $offset);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function nameContextFixtures(): array
    {
        return [
            'simple namespace + imports' => ['src/Domain/User.php', 10],
            'aliased import' => ['src/IncompleteCode/AliasedImports.php', 14],
            'group import' => ['src/IncompleteCode/GroupImports.php', 12],
            'group import with alias' => ['src/IncompleteCode/GroupImports.php', 38],
            'no imports' => ['src/Enum/Status.php', 10],
        ];
    }

    /**
     * Producer agreement (step-37): the skeleton and the parsed tree describe
     * the same file the same way, so a downstream reader that switches trees
     * cannot see a name context or a class shape that disagrees with the
     * parser. The corpus is every fixture the enclosing-class and name-context
     * sections use, plus every Domain fixture; each is parseable, so both
     * producers succeed on it.
     */
    #[DataProvider('producerAgreementFixtures')]
    public function testProducerAgreement(string $fixture): void
    {
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $content);
        $parsed = $this->parser->parse($document);
        $skeleton = (new SkeletonSyntaxSource())->parse($document);

        $parsedShape = self::describe($parsed);
        $skeletonShape = self::describe($skeleton);

        self::assertSame(
            $parsedShape,
            $skeletonShape,
            "the skeleton tree and the parsed tree must describe {$fixture} the same way",
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function producerAgreementFixtures(): array
    {
        $named = [
            'User (enclosing class)' => ['src/Domain/User.php'],
            'Entity (interface)' => ['src/Domain/Entity.php'],
            'HasTimestamps (trait)' => ['src/Traits/HasTimestamps.php'],
            'Status (enum)' => ['src/Enum/Status.php'],
            'AliasedImports (name context)' => ['src/IncompleteCode/AliasedImports.php'],
            'GroupImports (name context)' => ['src/IncompleteCode/GroupImports.php'],
        ];

        $domainDir = __DIR__ . '/../Fixtures/src/Domain';
        $entries = scandir($domainDir);
        foreach (($entries === false ? [] : $entries) as $entry) {
            if (!str_ends_with($entry, '.php')) {
                continue;
            }
            $key = 'Domain/' . $entry;
            $named[$key] = ['src/Domain/' . $entry];
        }

        return $named;
    }

    /**
     * The shape both producers must agree on: namespace, imports, class-like
     * names and kinds, and every member declaration's name, visibility, and
     * static-ness or readonly-ness — read through the same
     * {@see DeclarationScanner} and {@see DefaultClassInfoFactory} both sides
     * feed into. Line numbers and byte spans are producer-specific and
     * deliberately not compared.
     *
     * @param array<Stmt> $tree
     * @return array<string, mixed>
     */
    private static function describe(array $tree): array
    {
        $namespaces = [];
        foreach ($tree as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                $namespaces[] = [
                    'name' => $stmt->name?->toString() ?? '',
                    'imports' => self::importsOf($stmt->stmts),
                    'classLikes' => self::classLikesOf($stmt->stmts),
                ];
            }
        }

        $topImports = self::importsOf($tree);
        $topClassLikes = self::classLikesOf($tree);

        return [
            'namespaces' => $namespaces,
            'topLevelImports' => $topImports,
            'topLevelClassLikes' => $topClassLikes,
        ];
    }

    /**
     * @param array<Stmt> $stmts
     * @return list<array{type: string, name: string}>
     */
    private static function importsOf(array $stmts): array
    {
        $out = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Use_) {
                foreach ($stmt->uses as $use) {
                    $out[] = ['type' => 'use', 'name' => $use->name->toString()];
                }
            } elseif ($stmt instanceof Stmt\GroupUse) {
                $prefix = $stmt->prefix->toString();
                foreach ($stmt->uses as $use) {
                    $out[] = ['type' => 'group', 'name' => $prefix . '\\' . $use->name->toString()];
                }
            }
        }
        return $out;
    }

    /**
     * @param array<Stmt> $stmts
     * @return list<array{
     *   name: string,
     *   kind: string,
     *   methods: array<string, array<string, bool|string>>,
     *   properties: array<string, array<string, bool|string>>,
     *   constants: array<string, array<string, string>>,
     * }>
     */
    private static function classLikesOf(array $stmts): array
    {
        $factory = new DefaultClassInfoFactory();
        $out = [];
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof Stmt\ClassLike || $stmt->name === null) {
                continue;
            }
            $info = $factory->fromAstNode($stmt, 'file:///stub.php');
            $out[] = [
                'name' => $info->name->fqn,
                'kind' => match ($info->kind) {
                    ClassKind::Interface_ => 'interface',
                    ClassKind::Trait_ => 'trait',
                    ClassKind::Enum_ => 'enum',
                    default => 'class',
                },
                'methods' => self::describeMethods($info->methods),
                'properties' => self::describeProperties($info->properties),
                'constants' => self::describeConstants($info->constants),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, \Firehed\PhpLsp\Domain\MethodInfo> $methods
     * @return array<string, array<string, bool|string>>
     */
    private static function describeMethods(array $methods): array
    {
        $out = [];
        foreach ($methods as $name => $method) {
            $out[$name] = [
                'visibility' => $method->visibility->name,
                'isStatic' => $method->isStatic,
            ];
        }
        ksort($out);
        return $out;
    }

    /**
     * @param array<string, \Firehed\PhpLsp\Domain\PropertyInfo> $properties
     * @return array<string, array<string, bool|string>>
     */
    private static function describeProperties(array $properties): array
    {
        $out = [];
        foreach ($properties as $name => $property) {
            $out[$name] = [
                'visibility' => $property->visibility->name,
                'isStatic' => $property->isStatic,
                'isReadonly' => $property->isReadonly,
            ];
        }
        ksort($out);
        return $out;
    }

    /**
     * @param array<string, \Firehed\PhpLsp\Domain\ConstantInfo> $constants
     * @return array<string, array<string, string>>
     */
    private static function describeConstants(array $constants): array
    {
        $out = [];
        foreach ($constants as $name => $constant) {
            $out[$name] = ['visibility' => $constant->visibility->name];
        }
        ksort($out);
        return $out;
    }
}
