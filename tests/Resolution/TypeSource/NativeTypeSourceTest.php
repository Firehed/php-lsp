<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution\TypeSource;

use Closure;
use Firehed\PhpLsp\Domain\ClasslikeConstantInfo;
use Firehed\PhpLsp\Domain\ClasslikeConstantName;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolverInterface;
use Firehed\PhpLsp\Resolution\TypeSource\NativeTypeSource;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each declared slot carries a distinct type, so a lookup that reads the wrong
 * slot or the wrong parameter cannot pass.
 */
#[CoversClass(NativeTypeSource::class)]
final class NativeTypeSourceTest extends TestCase
{
    use BuildsSymbolInfoTrait;

    private const string OWNER = 'Fixture\\Owner';
    private const string FUNCTION = 'Fixture\\helper';
    private const string CONSTANT = 'Fixture\\MAX';

    private NativeTypeSource $source;

    protected function setUp(): void
    {
        $owner = self::className(self::OWNER);

        $constant = new ClasslikeConstantInfo(
            name: new ClasslikeConstantName($owner, 'LIMIT'),
            visibility: Visibility::Private,
            isFinal: false,
            type: self::type('ClassConstant'),
            docblock: null,
            file: null,
            line: null,
        );
        $method = new MethodInfo(
            name: new MethodName($owner, 'run'),
            visibility: Visibility::Private,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [
                self::parameter('first', 0, 'MethodFirstParameter'),
                self::parameter('second', 1, 'MethodSecondParameter'),
            ],
            returnType: self::type('MethodReturn'),
            docblock: null,
            file: null,
            line: null,
        );
        $property = new PropertyInfo(
            name: new PropertyName($owner, 'name'),
            visibility: Visibility::Private,
            isStatic: false,
            isReadonly: false,
            isPromoted: false,
            type: self::type('Property'),
            docblock: null,
            file: null,
            line: null,
        );

        $members = self::createStub(MemberResolverInterface::class);
        $members->method('findConstant')->willReturnCallback(
            static fn (ClasslikeName $class, string $name, Visibility $visibility): ?ClasslikeConstantInfo =>
                self::isOwnMember($class, $name, $visibility, 'LIMIT') ? $constant : null,
        );
        $members->method('findMethod')->willReturnCallback(
            static fn (ClasslikeName $class, string $name, Visibility $visibility): ?MethodInfo =>
                self::isOwnMember($class, $name, $visibility, 'run') ? $method : null,
        );
        $members->method('findProperty')->willReturnCallback(
            static fn (ClasslikeName $class, string $name, Visibility $visibility): ?PropertyInfo =>
                self::isOwnMember($class, $name, $visibility, 'name') ? $property : null,
        );

        $function = self::functionInfo(
            QualifiedName::fromFullyQualified(self::FUNCTION),
            parameters: [
                self::parameter('first', 0, 'FunctionFirstParameter'),
                self::parameter('second', 1, 'FunctionSecondParameter'),
            ],
            returnType: self::type('FunctionReturn'),
        );
        $globalConstant = self::constantInfo(
            QualifiedName::fromFullyQualified(self::CONSTANT),
            type: self::type('GlobalConstant'),
        );

        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('lookupFunction')->willReturnCallback(
            static fn (FunctionName $name): ?FunctionInfo =>
                $name->qualifiedName->fullyQualifiedName() === self::FUNCTION ? $function : null,
        );
        $symbols->method('lookupConstant')->willReturnCallback(
            static fn (ConstantName $name): ?ConstantInfo =>
                $name->qualifiedName->fullyQualifiedName() === self::CONSTANT ? $globalConstant : null,
        );

        $this->source = new NativeTypeSource($symbols, $members);
    }

    /**
     * @param Closure(NativeTypeSource): ?TypeInterface $query
     */
    #[DataProvider('declaredTypes')]
    public function testReturnsTheDeclaredType(Closure $query, TypeInterface $expected): void
    {
        self::assertEquals(
            $expected,
            $query($this->source),
            'the type comes from the declaration the collaborator found, at any visibility',
        );
    }

    /**
     * @param Closure(NativeTypeSource): ?TypeInterface $query
     */
    #[DataProvider('undeclaredSymbols')]
    public function testUndeclaredSymbolHasNoType(Closure $query): void
    {
        self::assertNull($query($this->source), 'nothing declares the symbol, so it has no type');
    }

    /**
     * @return array<string, array{Closure(NativeTypeSource): ?TypeInterface, TypeInterface}>
     */
    public static function declaredTypes(): array
    {
        $owner = self::className(self::OWNER);
        $function = FunctionName::fromFullyQualified(self::FUNCTION);

        return [
            'class constant' => [
                static fn (NativeTypeSource $s) => $s->forClassConstant(new ClasslikeConstantName($owner, 'LIMIT')),
                self::type('ClassConstant'),
            ],
            'global constant' => [
                static fn (NativeTypeSource $s) => $s->forGlobalConstant(
                    ConstantName::fromFullyQualified(self::CONSTANT),
                ),
                self::type('GlobalConstant'),
            ],
            'function return' => [
                static fn (NativeTypeSource $s) => $s->forFunctionReturn($function),
                self::type('FunctionReturn'),
            ],
            'function first parameter' => [
                static fn (NativeTypeSource $s) => $s->forFunctionParameter($function, 'first'),
                self::type('FunctionFirstParameter'),
            ],
            'function second parameter' => [
                static fn (NativeTypeSource $s) => $s->forFunctionParameter($function, 'second'),
                self::type('FunctionSecondParameter'),
            ],
            'method return' => [
                static fn (NativeTypeSource $s) => $s->forMethodReturn(new MethodName($owner, 'run')),
                self::type('MethodReturn'),
            ],
            'method first parameter' => [
                static fn (NativeTypeSource $s) => $s->forMethodParameter(new MethodName($owner, 'run'), 'first'),
                self::type('MethodFirstParameter'),
            ],
            'method second parameter' => [
                static fn (NativeTypeSource $s) => $s->forMethodParameter(new MethodName($owner, 'run'), 'second'),
                self::type('MethodSecondParameter'),
            ],
            'property' => [
                static fn (NativeTypeSource $s) => $s->forProperty(new PropertyName($owner, 'name')),
                self::type('Property'),
            ],
        ];
    }

    /**
     * @return array<string, array{Closure(NativeTypeSource): ?TypeInterface}>
     */
    public static function undeclaredSymbols(): array
    {
        $owner = self::className(self::OWNER);
        $function = FunctionName::fromFullyQualified(self::FUNCTION);
        $unknownFunction = FunctionName::fromFullyQualified('Fixture\\unknown');

        return [
            'class constant' => [
                static fn (NativeTypeSource $s) => $s->forClassConstant(new ClasslikeConstantName($owner, 'UNKNOWN')),
            ],
            'global constant' => [
                static fn (NativeTypeSource $s) => $s->forGlobalConstant(
                    ConstantName::fromFullyQualified('Fixture\\UNKNOWN'),
                ),
            ],
            'function return' => [
                static fn (NativeTypeSource $s) => $s->forFunctionReturn($unknownFunction),
            ],
            'parameter of unknown function' => [
                static fn (NativeTypeSource $s) => $s->forFunctionParameter($unknownFunction, 'first'),
            ],
            'unknown parameter of function' => [
                static fn (NativeTypeSource $s) => $s->forFunctionParameter($function, 'unknown'),
            ],
            'method return' => [
                static fn (NativeTypeSource $s) => $s->forMethodReturn(new MethodName($owner, 'unknown')),
            ],
            'parameter of unknown method' => [
                static fn (NativeTypeSource $s) => $s->forMethodParameter(new MethodName($owner, 'unknown'), 'first'),
            ],
            'unknown parameter of method' => [
                static fn (NativeTypeSource $s) => $s->forMethodParameter(new MethodName($owner, 'run'), 'unknown'),
            ],
            'property' => [
                static fn (NativeTypeSource $s) => $s->forProperty(new PropertyName($owner, 'unknown')),
            ],
        ];
    }

    /**
     * A member's type is wanted whatever its visibility, so only a lookup that
     * admits private members finds it.
     */
    private static function isOwnMember(
        ClasslikeName $class,
        string $name,
        Visibility $visibility,
        string $declared,
    ): bool {
        return $class->equals(self::className(self::OWNER))
            && $name === $declared
            && $visibility === Visibility::Private;
    }

    private static function parameter(string $name, int $position, string $typeLabel): ParameterInfo
    {
        return new ParameterInfo($name, self::type($typeLabel), false, null, $position, false, false);
    }

    private static function type(string $label): ClasslikeType
    {
        return new ClasslikeType(self::className('Fixture\\Types\\' . $label));
    }
}
