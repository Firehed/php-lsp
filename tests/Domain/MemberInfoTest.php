<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two questions the hierarchy walk asks of every member kind. They are
 * asserted together so no kind can answer them in its own terms.
 */
#[CoversClass(ConstantInfo::class)]
#[CoversClass(EnumCaseInfo::class)]
#[CoversClass(MethodInfo::class)]
#[CoversClass(PropertyInfo::class)]
class MemberInfoTest extends TestCase
{
    #[DataProvider('members')]
    public function testAnswersTheWalk(MemberInfo $member, Visibility $visibility, bool $isStatic): void
    {
        self::assertSame($visibility, $member->getVisibility());
        self::assertSame($isStatic, $member->isStatic());
    }

    /**
     * @return iterable<string, array{MemberInfo, Visibility, bool}>
     */
    public static function members(): iterable
    {
        $declaringClass = new ClassName(self::class);

        $constant = new ConstantInfo(
            name: new ConstantName('MAX'),
            visibility: Visibility::Protected,
            isFinal: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: $declaringClass,
        );
        // A constant is reached on the class, whatever its visibility says.
        yield 'constant' => [$constant, Visibility::Protected, true];

        $enumCase = new EnumCaseInfo(
            name: new EnumCaseName('Draft'),
            backingValue: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: $declaringClass,
        );
        yield 'enum case' => [$enumCase, Visibility::Public, true];

        $method = new MethodInfo(
            name: new MethodName('run'),
            visibility: Visibility::Private,
            isStatic: true,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: $declaringClass,
        );
        yield 'static method' => [$method, Visibility::Private, true];

        $property = new PropertyInfo(
            name: new PropertyName('value'),
            visibility: Visibility::Public,
            isStatic: false,
            isReadonly: false,
            isPromoted: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: $declaringClass,
        );
        yield 'instance property' => [$property, Visibility::Public, false];
    }
}
