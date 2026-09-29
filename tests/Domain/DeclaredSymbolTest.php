<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeclaredSymbol::class)]
final class DeclaredSymbolTest extends TestCase
{
    use BuildsSymbolInfoTrait;

    /**
     * @return iterable<string, array{DeclaredSymbol, string, NameKind, bool}>
     */
    public static function questions(): iterable
    {
        $class = self::declaredClass('App\Widget');
        $function = self::declaredFunction('App\widget');
        $constant = self::declaredConstant('App\WIDGET');

        yield 'same name and kind' => [$class, 'App\Widget', NameKind::ClassLike, true];
        yield 'class names ignore case' => [$class, 'app\WIDGET', NameKind::ClassLike, true];
        yield 'function names ignore case' => [$function, 'App\Widget', NameKind::Function_, true];
        yield 'constant namespaces ignore case' => [$constant, 'app\WIDGET', NameKind::Constant, true];
        yield 'constant short names keep case' => [$constant, 'App\widget', NameKind::Constant, false];
        yield 'same name, another kind' => [$class, 'App\Widget', NameKind::Function_, false];
        yield 'another name' => [$class, 'App\Gadget', NameKind::ClassLike, false];
    }

    #[DataProvider('questions')]
    public function testDeclares(DeclaredSymbol $symbol, string $name, NameKind $kind, bool $expected): void
    {
        self::assertSame(
            $expected,
            $symbol->declares(QualifiedName::fromFullyQualified($name), $kind),
            'a symbol answers to its name under its own kind\'s case rule, and to no other kind',
        );
    }
}
