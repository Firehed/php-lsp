<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Repository;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\DefaultFunctionRepository;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultFunctionRepository::class)]
class DefaultFunctionRepositoryTest extends TestCase
{
    use LoadsFixturesTrait;

    private DefaultFunctionRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new DefaultFunctionRepository();
    }

    /**
     * @param ?string $expectedName Short name of the resolved function, or null
     */
    #[DataProvider('resolutionProvider')]
    public function testGet(string $fixturePath, string $query, ?string $expectedName): void
    {
        $ast = $this->parseFixture($fixturePath);

        $result = $this->repository->get($query, $ast);

        self::assertSame(
            $expectedName,
            $result?->name,
            'Resolved function name should match the expected result',
        );
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     * @codeCoverageIgnore
     */
    public static function resolutionProvider(): iterable
    {
        yield 'global user function by name' => [
            'TypeInference/GlobalFunction.php',
            'getGlobalConfig',
            'getGlobalConfig',
        ];
        yield 'namespaced user function by FQN' => [
            'src/Completion/FunctionCompletion.php',
            'Fixtures\\Completion\\calculateSum',
            'calculateSum',
        ];
        yield 'namespaced user function by short name' => [
            'src/Completion/FunctionCompletion.php',
            'calculateSum',
            'calculateSum',
        ];
        yield 'built-in function via reflection' => [
            'TypeInference/GlobalFunction.php',
            'strlen',
            'strlen',
        ];
        yield 'unknown function' => [
            'TypeInference/GlobalFunction.php',
            'nonexistent_function_xyz',
            null,
        ];
    }

    public function testGetReturnsFullMetadata(): void
    {
        $ast = $this->parseFixture('src/Completion/FunctionCompletion.php');

        $result = $this->repository->get('Fixtures\\Completion\\calculateSum', $ast);

        self::assertNotNull($result, 'calculateSum should resolve from the fixture');
        self::assertCount(2, $result->parameters, 'calculateSum declares two parameters');
        self::assertNotNull($result->returnType, 'calculateSum declares a return type');
        self::assertSame('int', $result->returnType->format(), 'Return type should be int');
        self::assertNotNull($result->docblock, 'calculateSum carries a docblock');
        self::assertStringContainsString(
            'Adds two numbers',
            $result->docblock,
            'Docblock content should be preserved',
        );
    }

    public function testGetResolvesReturnTypeToClassName(): void
    {
        $ast = $this->parseFixture('src/TypeInference/FunctionTypes.php');

        $result = $this->repository->get('Fixtures\\TypeInference\\getUser', $ast);

        self::assertNotNull($result, 'getUser should resolve from the fixture');
        self::assertInstanceOf(ClassName::class, $result->returnType);
        self::assertSame('Fixtures\\Domain\\User', $result->returnType->fqn);
    }

    /**
     * @return array<Stmt>
     */
    private function parseFixture(string $fixturePath): array
    {
        $parser = new ParserService();
        $document = new TextDocument('file:///test.php', 'php', 1, $this->loadFixture($fixturePath));
        return $parser->parse($document) ?? [];
    }
}
