<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Index\NodeAtPosition;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\Message;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

final class HoverHandler implements HandlerInterface
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ParserService $parser,
        private readonly ?ComposerClassLocator $classLocator,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === 'textDocument/hover';
    }

    /**
     * @return array{contents: string}|null
     */
    public function handle(Message $message): ?array
    {
        $params = $message->params ?? [];

        $textDocument = $params['textDocument'] ?? [];
        if (!is_array($textDocument)) {
            return null;
        }
        $uri = $textDocument['uri'] ?? '';
        if (!is_string($uri)) {
            return null;
        }

        $position = $params['position'] ?? [];
        if (!is_array($position)) {
            return null;
        }
        $line = $position['line'] ?? 0;
        $character = $position['character'] ?? 0;
        if (!is_int($line) || !is_int($character)) {
            return null;
        }

        $document = $this->documentManager->get($uri);
        if ($document === null) {
            return null;
        }

        $ast = $this->parser->parse($document);
        if ($ast === null) {
            return null;
        }

        $offset = $document->offsetAt($line, $character);
        $nodeFinder = new NodeAtPosition();
        $node = $nodeFinder->find($ast, $offset);

        if ($node === null) {
            return null;
        }

        $hoverContent = $this->getHoverContent($node, $ast, $document);

        if ($hoverContent === null) {
            return null;
        }

        return ['contents' => $hoverContent];
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getHoverContent(Node $node, array $ast, TextDocument $document): ?string
    {
        // Name node - could be class reference or function call
        if ($node instanceof Name) {
            $parent = $node->getAttribute('parent');
            // Function call: check for function definition first
            if ($parent instanceof FuncCall) {
                $functionHover = $this->getFunctionHover($node->toString(), $ast);
                if ($functionHover !== null) {
                    return $functionHover;
                }
            }
            // Fall through to class hover for class references
            return $this->getClassHover($node, $ast, $document);
        }

        // Method name in a method call (Identifier node)
        if ($node instanceof Identifier) {
            $parent = $node->getAttribute('parent');
            if ($parent instanceof FuncCall) {
                return $this->getFunctionHover($node->toString(), $ast);
            }
        }

        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getClassHover(Name $node, array $ast, TextDocument $document): ?string
    {
        $resolvedName = $node->getAttribute('resolvedName');
        $className = $resolvedName instanceof Name
            ? $resolvedName->toString()
            : $node->toString();

        // First look in current file
        $classNode = $this->findClassInAst($className, $ast);

        // If not found, try to locate via Composer
        if ($classNode === null && $this->classLocator !== null) {
            $filePath = $this->classLocator->locateClass($className);
            if ($filePath !== null) {
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    $externalDoc = new TextDocument('file://' . $filePath, 'php', 0, $content);
                    $externalAst = $this->parser->parse($externalDoc);
                    if ($externalAst !== null) {
                        $classNode = $this->findClassInAst($className, $externalAst);
                    }
                }
            }
        }

        if ($classNode === null) {
            return null;
        }

        return $this->formatClassHover($classNode);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findClassInAst(string $className, array $ast): Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null
    {
        $finder = new class ($className) extends NodeVisitorAbstract {
            public Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null $found = null;
            private string $namespace = '';

            public function __construct(private readonly string $className)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString() ?? '';
                    return null;
                }

                if ($node instanceof Stmt\Class_
                    || $node instanceof Stmt\Interface_
                    || $node instanceof Stmt\Trait_
                    || $node instanceof Stmt\Enum_
                ) {
                    $name = $node->name?->toString();
                    if ($name === null) {
                        return null;
                    }
                    $fqn = $this->namespace !== '' ? $this->namespace . '\\' . $name : $name;

                    // Match by FQN or short name
                    if ($fqn === $this->className || $name === $this->className) {
                        $this->found = $node;
                        return NodeTraverser::STOP_TRAVERSAL;
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        return $finder->found;
    }

    private function formatClassHover(Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_ $node): string
    {
        $parts = [];

        // Add docblock if present
        $docComment = $node->getDocComment();
        if ($docComment !== null) {
            $parts[] = $this->formatDocblock($docComment->getText());
        }

        // Add signature
        $keyword = match (true) {
            $node instanceof Stmt\Interface_ => 'interface',
            $node instanceof Stmt\Trait_ => 'trait',
            $node instanceof Stmt\Enum_ => 'enum',
            default => 'class',
        };

        $signature = $keyword . ' ' . $node->name;

        if ($node instanceof Stmt\Class_) {
            if ($node->extends !== null) {
                $signature .= ' extends ' . $node->extends->toString();
            }
            if ($node->implements !== []) {
                $implements = array_map(fn($n) => $n->toString(), $node->implements);
                $signature .= ' implements ' . implode(', ', $implements);
            }
        }

        if ($node instanceof Stmt\Interface_ && $node->extends !== []) {
            $extends = array_map(fn($n) => $n->toString(), $node->extends);
            $signature .= ' extends ' . implode(', ', $extends);
        }

        $parts[] = '```php' . "\n" . $signature . "\n```";

        return implode("\n\n", $parts);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getFunctionHover(string $functionName, array $ast): ?string
    {
        $finder = new class ($functionName) extends NodeVisitorAbstract {
            public ?Stmt\Function_ $found = null;

            public function __construct(private readonly string $functionName)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Function_ && $node->name->toString() === $this->functionName) {
                    $this->found = $node;
                    return NodeTraverser::STOP_TRAVERSAL;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        if ($finder->found === null) {
            return null;
        }

        return $this->formatFunctionHover($finder->found);
    }

    private function formatFunctionHover(Stmt\Function_ $node): string
    {
        $parts = [];

        // Add docblock if present
        $docComment = $node->getDocComment();
        if ($docComment !== null) {
            $parts[] = $this->formatDocblock($docComment->getText());
        }

        // Build signature
        $params = [];
        foreach ($node->params as $param) {
            $paramStr = '';
            if ($param->type !== null) {
                $paramStr .= $this->formatType($param->type) . ' ';
            }
            $var = $param->var;
            if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
                $paramStr .= '$' . $var->name;
            }
            $params[] = $paramStr;
        }

        $signature = 'function ' . $node->name->toString() . '(' . implode(', ', $params) . ')';

        if ($node->returnType !== null) {
            $signature .= ': ' . $this->formatType($node->returnType);
        }

        $parts[] = '```php' . "\n" . $signature . "\n```";

        return implode("\n\n", $parts);
    }

    private function formatDocblock(string $docblock): string
    {
        // Strip /** and */ and clean up
        $lines = explode("\n", $docblock);
        $cleaned = [];

        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^\/\*\*\s*/', '', $line) ?? '';
            $line = preg_replace('/^\*\/\s*$/', '', $line) ?? '';
            $line = preg_replace('/^\*\s?/', '', $line) ?? '';

            if ($line !== '') {
                $cleaned[] = $line;
            }
        }

        return implode("\n", $cleaned);
    }

    private function formatType(Node $type): string
    {
        if ($type instanceof Name) {
            return $type->toString();
        }
        if ($type instanceof Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return '?' . $this->formatType($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn($t) => $this->formatType($t), $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(fn($t) => $this->formatType($t), $type->types));
        }
        return '';
    }
}
