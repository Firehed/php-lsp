<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\MagicMethod;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MagicMethod::class)]
final class MagicMethodTest extends TestCase
{
    /**
     * @return iterable<string, array{ClassKind, bool, Visibility, list<MagicMethod>}>
     */
    public static function declarations(): iterable
    {
        $instance = [
            MagicMethod::Call,
            MagicMethod::Clone,
            MagicMethod::Construct,
            MagicMethod::DebugInfo,
            MagicMethod::Destruct,
            MagicMethod::Get,
            MagicMethod::Invoke,
            MagicMethod::Isset,
            MagicMethod::Serialize,
            MagicMethod::Set,
            MagicMethod::Sleep,
            MagicMethod::ToString,
            MagicMethod::Unserialize,
            MagicMethod::Unset,
            MagicMethod::Wakeup,
        ];
        $static = [MagicMethod::CallStatic, MagicMethod::SetState];
        $nonPublic = [MagicMethod::Clone, MagicMethod::Construct, MagicMethod::Destruct];

        foreach ([ClassKind::Class_, ClassKind::Interface_, ClassKind::Trait_] as $kind) {
            yield "{$kind->name}, public" => [$kind, false, Visibility::Public, $instance];
            yield "{$kind->name}, public static" => [$kind, true, Visibility::Public, $static];
            yield "{$kind->name}, protected" => [$kind, false, Visibility::Protected, $nonPublic];
            yield "{$kind->name}, private" => [$kind, false, Visibility::Private, $nonPublic];
            yield "{$kind->name}, private static" => [$kind, true, Visibility::Private, []];
        }
        yield 'enum, public' => [ClassKind::Enum_, false, Visibility::Public, [MagicMethod::Call, MagicMethod::Invoke]];
        yield 'enum, public static' => [ClassKind::Enum_, true, Visibility::Public, [MagicMethod::CallStatic]];
        yield 'enum, private' => [ClassKind::Enum_, false, Visibility::Private, []];
    }

    /**
     * @param list<MagicMethod> $expected
     */
    #[DataProvider('declarations')]
    public function testOnlyWhatPhpAllowsIsDeclarable(
        ClassKind $kind,
        bool $static,
        Visibility $visibility,
        array $expected,
    ): void {
        $declarable = array_values(array_filter(
            MagicMethod::cases(),
            static fn (MagicMethod $method): bool => $method->declarableAs($kind, $static, $visibility),
        ));

        self::assertSame(
            $expected,
            $declarable,
            'enums allow only __call, __callStatic, and __invoke; two are static; three may be non-public',
        );
    }

    public function testEachSignatureDeclaresItsMethod(): void
    {
        foreach (MagicMethod::cases() as $method) {
            self::assertStringStartsWith($method->value . '(', $method->signature(), 'a signature names its method');
        }
    }

    public function testAConstructorOrDestructorDeclaresNoReturnType(): void
    {
        self::assertSame('__construct()', MagicMethod::Construct->signature(), 'a return type is a fatal error');
        self::assertSame('__destruct()', MagicMethod::Destruct->signature(), 'a return type is a fatal error');
    }
}
