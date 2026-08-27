<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\NameContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NameContextFactory::class)]
final class NameContextFactoryTest extends TestCase
{
    #[DataProvider('textCases')]
    public function testFromText(
        string $source,
        int $line,
        string $expectedNamespace,
        array $expectedClassImports,
        string $message,
    ): void {
        $lines = explode("\n", $source);
        $context = NameContextFactory::fromText($lines, $line);

        self::assertSame($expectedNamespace, $context->namespace, $message . ' (namespace)');
        self::assertSame($expectedClassImports, $context->classImports, $message . ' (class imports)');
    }

    /**
     * @return iterable<string, array{string, int, string, array<string, string>, string}>
     */
    public static function textCases(): iterable
    {
        yield 'simple namespace and use' => [
            <<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            class User extends Model
            {
            }
            PHP,
            7,
            'App\\Models',
            ['Model' => 'Illuminate\\Database\\Eloquent\\Model'],
            'Simple namespace and use statement',
        ];

        yield 'aliased use' => [
            <<<'PHP'
            <?php

            namespace App\Controllers;

            use App\Models\User as UserModel;

            class UserController
            {
            }
            PHP,
            6,
            'App\\Controllers',
            ['UserModel' => 'App\\Models\\User'],
            'Aliased use statement',
        ];

        yield 'group use' => [
            <<<'PHP'
            <?php

            namespace App\Controllers;

            use App\Models\{User, Order};

            class UserController
            {
            }
            PHP,
            6,
            'App\\Controllers',
            ['User' => 'App\\Models\\User', 'Order' => 'App\\Models\\Order'],
            'Group use statement',
        ];

        yield 'group use with alias' => [
            <<<'PHP'
            <?php

            namespace App\Controllers;

            use App\Models\{User, Order as PurchaseOrder};

            class UserController
            {
            }
            PHP,
            6,
            'App\\Controllers',
            ['User' => 'App\\Models\\User', 'PurchaseOrder' => 'App\\Models\\Order'],
            'Group use with alias',
        ];

        yield 'no namespace' => [
            <<<'PHP'
            <?php

            use App\Models\User;

            class UserController
            {
            }
            PHP,
            5,
            '',
            ['User' => 'App\\Models\\User'],
            'No namespace declaration',
        ];

        yield 'stops at class declaration' => [
            <<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            class User extends Model
            {
                use SomeTrait;
            }
            PHP,
            8,
            'App\\Models',
            ['Model' => 'Illuminate\\Database\\Eloquent\\Model'],
            'Trait use inside class body not treated as import',
        ];
    }
}
