<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\TypeInference;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\TypeInference\PhpStanTypeInferenceService;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpStanTypeInferenceService::class)]
class PhpStanTypeInferenceServiceTest extends TestCase
{
    private PhpStanTypeInferenceService $service;

    protected function setUp(): void
    {
        $this->service = new PhpStanTypeInferenceService();
    }

    public function testGetVariableTypeInfersFromAssignment(): void
    {
        // Use a real class that's autoloaded
        $code = <<<'PHP'
<?php

class Example {
    public function test(): void {
        $doc = new \Firehed\PhpLsp\Document\TextDocument('uri', 'php', 1, 'content');
        echo $doc->uri;
    }
}
PHP;

        $document = new TextDocument('file:///test.php', 'php', 1, $code);

        // Line 5 is where $doc is assigned
        $type = $this->service->getVariableType($document, 'doc', 5);

        self::assertNotNull($type);
        self::assertStringContainsString('TextDocument', $type);
    }

    public function testGetVariableTypeInfersFromMethodReturn(): void
    {
        // Use DocumentManager which has a method returning TextDocument|null
        $code = <<<'PHP'
<?php

class Example {
    public function test(\Firehed\PhpLsp\Document\DocumentManager $manager): void {
        $doc = $manager->get('file:///test.php');
        if ($doc !== null) {
            echo $doc->uri;
        }
    }
}
PHP;

        $document = new TextDocument('file:///test.php', 'php', 1, $code);

        // Line 5 is where $doc is assigned
        $type = $this->service->getVariableType($document, 'doc', 5);

        self::assertNotNull($type);
        // get() returns TextDocument|null
        self::assertStringContainsString('TextDocument', $type);
    }

    public function testGetExpressionTypeForMethodCall(): void
    {
        $code = <<<'PHP'
<?php

class Example {
    public function test(): void {
        $doc = new \Firehed\PhpLsp\Document\TextDocument('uri', 'php', 1, 'content');
        $content = $doc->getContent();
    }
}
PHP;

        $document = new TextDocument('file:///test.php', 'php', 1, $code);

        // Parse and find the MethodCall node
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);
        self::assertNotNull($ast);

        $methodCall = $this->findNode($ast, Node\Expr\MethodCall::class);
        self::assertNotNull($methodCall);

        // Line 6 is where the method call happens
        $type = $this->service->getExpressionType($document, $methodCall, 6);

        self::assertNotNull($type);
        self::assertSame('string', $type);
    }

    public function testGetExpressionTypeForPropertyFetch(): void
    {
        $code = <<<'PHP'
<?php

class Example {
    public function test(): void {
        $doc = new \Firehed\PhpLsp\Document\TextDocument('uri', 'php', 1, 'content');
        $uri = $doc->uri;
    }
}
PHP;

        $document = new TextDocument('file:///test.php', 'php', 1, $code);

        // Parse and find the PropertyFetch node
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);
        self::assertNotNull($ast);

        $propertyFetch = $this->findNode($ast, Node\Expr\PropertyFetch::class);
        self::assertNotNull($propertyFetch);

        // Line 6 is where the property fetch happens
        $type = $this->service->getExpressionType($document, $propertyFetch, 6);

        self::assertNotNull($type);
        self::assertSame('string', $type);
    }

    public function testInvalidateClearsCache(): void
    {
        $code1 = <<<'PHP'
<?php

class Example {
    public function test(): void {
        $value = 'hello';
    }
}
PHP;

        $code2 = <<<'PHP'
<?php

class Example {
    public function test(): void {
        $value = new \Firehed\PhpLsp\Document\TextDocument('uri', 'php', 1, 'content');
    }
}
PHP;

        $uri = 'file:///test.php';
        $document1 = new TextDocument($uri, 'php', 1, $code1);

        // First analysis - $value is not typed (string literal)
        $type1 = $this->service->getVariableType($document1, 'value', 5);
        // String literals aren't tracked, so this will be null
        self::assertNull($type1);

        // Invalidate cache
        $this->service->invalidate($uri);

        // Second analysis with different code - $value is TextDocument
        $document2 = new TextDocument($uri, 'php', 2, $code2);
        $type2 = $this->service->getVariableType($document2, 'value', 5);

        self::assertNotNull($type2);
        self::assertStringContainsString('TextDocument', $type2);
    }

    public function testReturnsNullForUnknownVariable(): void
    {
        $code = <<<'PHP'
<?php

class Example {
    public function test(): void {
        $known = new \stdClass();
    }
}
PHP;

        $document = new TextDocument('file:///test.php', 'php', 1, $code);

        $type = $this->service->getVariableType($document, 'unknown', 5);

        self::assertNull($type);
    }

    public function testGetMethodReturnType(): void
    {
        // Use a real autoloaded class
        $type = $this->service->getMethodReturnType(
            TextDocument::class,
            'getContent',
        );

        self::assertNotNull($type);
        self::assertSame('string', $type);
    }

    public function testGetPropertyType(): void
    {
        // Use a real autoloaded class
        $type = $this->service->getPropertyType(
            TextDocument::class,
            'uri',
        );

        self::assertNotNull($type);
        self::assertSame('string', $type);
    }

    public function testHasClass(): void
    {
        self::assertTrue($this->service->hasClass(TextDocument::class));
        self::assertFalse($this->service->hasClass('NonExistent\\FakeClass'));
    }

    public function testGetVariableTypeFromMethodParameter(): void
    {
        $code = <<<'PHP'
<?php

class Example {
    public function test(\Firehed\PhpLsp\Document\TextDocument $doc): void {
        echo $doc->uri;
    }
}
PHP;

        $document = new TextDocument('file:///test.php', 'php', 1, $code);

        // $doc is a parameter, available throughout the method
        $type = $this->service->getVariableType($document, 'doc', 5);

        self::assertNotNull($type);
        self::assertStringContainsString('TextDocument', $type);
    }

    /**
     * Helper to find a node of a specific type in the AST.
     *
     * @template T of Node
     * @param array<Node\Stmt> $ast
     * @param class-string<T> $nodeClass
     * @return T|null
     */
    private function findNode(array $ast, string $nodeClass): ?Node
    {
        foreach ($ast as $stmt) {
            $found = $this->findNodeRecursive($stmt, $nodeClass);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /**
     * @template T of Node
     * @param class-string<T> $nodeClass
     * @return T|null
     */
    private function findNodeRecursive(Node $node, string $nodeClass): ?Node
    {
        if ($node instanceof $nodeClass) {
            return $node;
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNode = $node->$subNodeName;
            if ($subNode instanceof Node) {
                $found = $this->findNodeRecursive($subNode, $nodeClass);
                if ($found !== null) {
                    return $found;
                }
            } elseif (is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node) {
                        $found = $this->findNodeRecursive($item, $nodeClass);
                        if ($found !== null) {
                            return $found;
                        }
                    }
                }
            }
        }

        return null;
    }
}
