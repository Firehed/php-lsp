<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Repository;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Repository\MemberEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberEntry::class)]
final class MemberEntryTest extends TestCase
{
    /**
     * PHP checks protected access against the prototype's class, else the
     * member's own.
     */
    public function testTheRootIsThePrototypesDeclarerElseItsOwn(): void
    {
        $base = self::classInfo('Fixtures\Base');
        $sub = self::classInfo('Fixtures\Sub');
        $inherited = new MemberEntry(self::method($base), $base);

        $own = new MemberEntry(self::method($sub), $sub);
        $overriding = $own->withPrototype($inherited);

        self::assertSame($own, $own->rootEntry(), 'no prototype: itself');
        self::assertSame($inherited, $overriding->rootEntry(), 'its prototype');
        self::assertSame($sub->name, $own->root(), 'no prototype: its own class');
        self::assertSame($base->name, $overriding->root(), 'the prototype\'s class');
        self::assertSame($own->member, $overriding->member, 'the member is unchanged');
        self::assertSame($sub, $overriding->declaring, 'the declaring class is unchanged');
    }

    private static function classInfo(string $name): ClassInfo
    {
        return new ClassInfo(
            name: ClasslikeName::fromFullyQualified($name),
            kind: ClassKind::Class_,
            isAbstract: false,
            isFinal: false,
            isReadonly: false,
            isAttribute: false,
            parent: null,
            interfaces: [],
            traits: [],
            methods: [],
            properties: [],
            constants: [],
            enumCases: [],
            docblock: null,
            file: null,
            line: null,
        );
    }

    private static function method(ClassInfo $owner): MethodInfo
    {
        return new MethodInfo(
            name: new MethodName($owner->name, 'run'),
            visibility: Visibility::Protected,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: null,
            docblock: null,
            file: null,
            line: null,
        );
    }
}
