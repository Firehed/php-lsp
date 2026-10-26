<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use Firehed\PhpLsp\Tests\Domain\HasSymbolLocationTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MethodInfo::class)]
class MethodInfoTest extends TestCase
{
    use HasSymbolLocationTestTrait;

    public function testLateBindingBindsTheReturnAndParameterTypes(): void
    {
        $called = ClasslikeName::fromFullyQualified('App\\Called');
        $method = new MethodInfo(
            name: new MethodName(ClasslikeName::fromFullyQualified('App\\Declaring'), 'with'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [
                new ParameterInfo('other', new LateStaticType(LateBindingKeyword::Self), null, 0, false, false),
            ],
            returnType: new LateStaticType(LateBindingKeyword::Static),
            docblock: '/** Doc. */',
            file: '/f.php',
            line: 3,
        );

        $bound = $method->resolveLateBound(new LateBinding($called, $called, null));

        self::assertSame('App\\Called', $bound->returnType?->format(), 'the return type is bound');
        self::assertSame('App\\Called', $bound->parameters[0]->type?->format(), 'each parameter type is bound');
        self::assertSame($method->name, $bound->name, 'the rest is kept');
        self::assertSame('/** Doc. */', $bound->docblock);
    }

    public function testLateBindingKeepsAMethodWithNothingToBind(): void
    {
        $method = new MethodInfo(
            name: new MethodName(ClasslikeName::fromFullyQualified('App\\Declaring'), 'run'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [new ParameterInfo('count', PrimitiveType::Int, null, 0, false, false)],
            returnType: PrimitiveType::Void,
            docblock: null,
            file: null,
            line: null,
        );
        $called = ClasslikeName::fromFullyQualified('App\\Called');

        self::assertSame($method, $method->resolveLateBound(new LateBinding($called, $called, null)));
    }

    public function testConstruction(): void
    {
        $owner = ClasslikeName::fromFullyQualified(MethodInfo::class);
        $method = new MethodInfo(
            name: new MethodName($owner, 'doSomething'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: true,
            parameters: [],
            returnType: PrimitiveType::Void,
            docblock: null,
            file: '/path/to/file.php',
            line: 42,
        );

        self::assertSame('doSomething', $method->name->name);
        self::assertSame(Visibility::Public, $method->visibility);
        self::assertFalse($method->isStatic);
        self::assertFalse($method->isAbstract);
        self::assertTrue($method->isFinal);
        self::assertSame([], $method->parameters);
        self::assertSame('void', $method->returnType?->format());
        self::assertNull($method->docblock);
        self::assertSame('/path/to/file.php', $method->file);
        self::assertSame(42, $method->line);
        self::assertSame(MethodInfo::class, $method->name->owner->qualifiedName->fullyQualifiedName());
        self::assertNull($method->aliasedFrom, 'a straight-declared method has no alias source');
    }

    public function testAliasedFrom(): void
    {
        $user = ClasslikeName::fromFullyQualified('Some\\Namespace\\User');
        $trait = ClasslikeName::fromFullyQualified('Some\\Namespace\\NamedTrait');
        $method = new MethodInfo(
            name: new MethodName($user, 'exposedName'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: null,
            docblock: null,
            file: null,
            line: null,
            aliasedFrom: new MethodName($trait, 'originalName'),
        );

        self::assertSame($user, $method->getName()->owner, 'the aliasing class owns an aliased method identity');
        self::assertNotNull($method->aliasedFrom);
        self::assertSame($trait, $method->aliasedFrom->owner, 'aliasedFrom carries the source trait');
        self::assertSame('originalName', $method->aliasedFrom->name);
    }

    public function testFormatNoParamsNoReturnType(): void
    {
        $method = new MethodInfo(
            name: new MethodName(ClasslikeName::fromFullyQualified(self::class), 'doSomething'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: null,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('public function doSomething()', $method->format());
    }

    public function testFormatWithReturnType(): void
    {
        $method = new MethodInfo(
            name: new MethodName(ClasslikeName::fromFullyQualified(self::class), 'getName'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: PrimitiveType::String,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('public function getName(): string', $method->format());
    }

    public function testFormatWithParameters(): void
    {
        $method = new MethodInfo(
            name: new MethodName(ClasslikeName::fromFullyQualified(self::class), 'setName'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [
                new ParameterInfo('name', PrimitiveType::String, null, 0, false, false),
            ],
            returnType: null,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('public function setName(string $name)', $method->format());
    }

    public function testFormatWithMultipleParametersAndReturnType(): void
    {
        $method = new MethodInfo(
            name: new MethodName(ClasslikeName::fromFullyQualified(self::class), 'calculate'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [
                new ParameterInfo('a', PrimitiveType::Int, null, 0, false, false),
                new ParameterInfo('b', PrimitiveType::Int, null, 1, false, false),
            ],
            returnType: PrimitiveType::Int,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('public function calculate(int $a, int $b): int', $method->format());
    }

    public function testFormatWithVariadicParameter(): void
    {
        $method = new MethodInfo(
            name: new MethodName(ClasslikeName::fromFullyQualified(self::class), 'merge'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [
                new ParameterInfo('arrays', PrimitiveType::Array, null, 0, true, false),
            ],
            returnType: PrimitiveType::Array,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('public function merge(array ...$arrays): array', $method->format());
    }

    public function testFormatWithReferenceParameter(): void
    {
        $method = new MethodInfo(
            name: new MethodName(ClasslikeName::fromFullyQualified(self::class), 'swap'),
            visibility: Visibility::Public,
            isStatic: true,
            isAbstract: false,
            isFinal: false,
            parameters: [
                new ParameterInfo('a', PrimitiveType::Mixed, null, 0, false, true),
                new ParameterInfo('b', PrimitiveType::Mixed, null, 1, false, true),
            ],
            returnType: PrimitiveType::Void,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('public static function swap(mixed &$a, mixed &$b): void', $method->format());
    }

    public function testFormatAbstractMethod(): void
    {
        $method = new MethodInfo(
            name: new MethodName(ClasslikeName::fromFullyQualified(self::class), 'handle'),
            visibility: Visibility::Protected,
            isStatic: false,
            isAbstract: true,
            isFinal: false,
            parameters: [],
            returnType: PrimitiveType::Void,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('protected abstract function handle(): void', $method->format());
    }

    public function testFormatFinalMethod(): void
    {
        $method = new MethodInfo(
            name: new MethodName(ClasslikeName::fromFullyQualified(self::class), 'getInstance'),
            visibility: Visibility::Private,
            isStatic: true,
            isAbstract: false,
            isFinal: true,
            parameters: [],
            returnType: new LateStaticType(LateBindingKeyword::Self),
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('private static final function getInstance(): self', $method->format());
    }

    public function testResolvedMemberMetadata(): void
    {
        $method = $this->makeSubject();

        self::assertSame(MemberKind::Method, $method->getMemberKind());
        self::assertSame('doSomething', $method->getName()->name);
        self::assertSame(MethodInfo::class, $method->getName()->owner->qualifiedName->fullyQualifiedName());
        self::assertSame(Visibility::Public, $method->getVisibility());
        self::assertFalse($method->isStatic());
    }

    public function testReportsTheVisibilityAndStaticnessItWasDeclaredWith(): void
    {
        $method = new MethodInfo(
            name: new MethodName(ClasslikeName::fromFullyQualified(self::class), 'run'),
            visibility: Visibility::Private,
            isStatic: true,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: null,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame(Visibility::Private, $method->getVisibility(), 'a private method keeps its visibility');
        self::assertTrue($method->isStatic(), 'a static method reports it');
    }

    public function testResolvedCallableMetadata(): void
    {
        $param = new ParameterInfo(
            name: 'value',
            type: PrimitiveType::String,
            defaultValue: null,
            position: 0,
            isVariadic: false,
            isPassedByReference: false,
        );
        $method = $this->makeSubject(parameters: [$param]);

        self::assertSame('int', $method->getType()?->format());
        self::assertSame([$param], $method->getParameters());
        self::assertSame($param, $method->getParameterByName('value'));
        self::assertNull($method->getParameterByName('missing'));
    }

    /**
     * @param list<ParameterInfo> $parameters
     */
    protected function makeSubject(
        ?string $file = null,
        ?int $line = null,
        ?string $docblock = null,
        array $parameters = [],
    ): MethodInfo {
        return new MethodInfo(
            name: new MethodName(ClasslikeName::fromFullyQualified(MethodInfo::class), 'doSomething'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: $parameters,
            returnType: PrimitiveType::Int,
            docblock: $docblock,
            file: $file,
            line: $line,
        );
    }
}
