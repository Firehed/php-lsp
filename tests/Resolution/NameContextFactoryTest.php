<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Resolution\NameContextFactory;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NameContextFactory::class)]
final class NameContextFactoryTest extends TestCase
{
    /**
     * @param array<string, string> $expectedClassImports
     */
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

    public function testFromAstOrTextPrefersAstWhenAvailable(): void
    {
        // The text regex picks up the `use` inside the string as an import.
        // The AST path ignores it. fromAstOrText must prefer the AST path.
        $source = <<<'PHP'
            <?php
            namespace App\Models;
            use Real\Import;
            $x = "
            use Fake\Import;
            ";
            PHP;
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($source);
        self::assertNotNull($ast);

        $lines = explode("\n", $source);
        $line = 5;

        $fromAstOrText = NameContextFactory::fromAstOrText($ast, $line, $lines);
        $fromAst = NameContextFactory::fromAst($ast, $line);
        $fromText = NameContextFactory::fromText($lines, $line);

        self::assertSame(
            $fromAst->classImports,
            $fromAstOrText->classImports,
            'fromAstOrText must match AST path, not text path',
        );
        self::assertNotSame(
            $fromText->classImports,
            $fromAst->classImports,
            'Text and AST paths must disagree for this test to be meaningful',
        );
    }
}
