<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\SkeletonSyntaxSource;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Resolution\NameContextFactory;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NameContextFactory::class)]
final class NameContextFactoryTest extends TestCase
{
    private SkeletonSyntaxSource $skeleton;

    protected function setUp(): void
    {
        $this->skeleton = new SkeletonSyntaxSource(new TreeAnnotator(tolerant: true));
    }

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
        $document = new TextDocument('file:///t.php', 'php', 1, $source);
        $context = NameContextFactory::fromText($document, $line, $this->skeleton);

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

        $document = new TextDocument('file:///t.php', 'php', 1, $source);
        $line = 5;

        $fromAstOrText = NameContextFactory::fromAstOrText($ast, $line, $document, $this->skeleton);
        $fromAst = NameContextFactory::fromAst($ast, $line);
        $fromText = NameContextFactory::fromText($document, $line, $this->skeleton);

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

    public function testFromAstOrTextFallsBackToTheSkeletonWhenTheAstHasNoNamespaceOrUse(): void
    {
        // An AST whose php-parser recovery kept only a bare expression statement
        // has no namespace or use node, so fromAstOrText falls through to the
        // skeleton, which re-parses the document from text.
        $source = "<?php\nnamespace App;\nuse Vendor\\Widget;\necho 1;\n";
        $document = new TextDocument('file:///t.php', 'php', 1, $source);
        $bareAst = [new \PhpParser\Node\Stmt\Expression(
            new \PhpParser\Node\Scalar\Int_(1),
        )];

        $context = NameContextFactory::fromAstOrText($bareAst, 3, $document, $this->skeleton);

        self::assertSame('App', $context->namespace, 'the skeleton recovers the namespace from text');
        self::assertSame(
            ['Widget' => 'Vendor\\Widget'],
            $context->classImports,
            'the skeleton recovers the import from text',
        );
    }
}
