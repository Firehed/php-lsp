<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyInfo as DomainPropertyInfo;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\NodeAtPosition;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Repository\ClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Utility\DocblockParser;
use Firehed\PhpLsp\Utility\MemberAccessResolver;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use ReflectionException;
use ReflectionFunction;

final class HoverHandler implements HandlerInterface
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ParserService $parser,
        private readonly ClassRepository $classRepository,
        private readonly MemberResolver $memberResolver,
        private readonly MemberAccessResolver $memberAccessResolver,
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

        $hoverContent = $this->getHoverContent($node, $ast);

        if ($hoverContent === null) {
            return null;
        }

        return ['contents' => $hoverContent];
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getHoverContent(Node $node, array $ast): ?string
    {
        // Name node - could be class reference or function call
        if ($node instanceof Name) {
            $parent = $node->getAttribute('parent');

            // Function call: check for user-defined function first, then built-in
            if ($parent instanceof FuncCall) {
                $functionHover = $this->getFunctionHover($node->toString(), $ast);
                if ($functionHover !== null) {
                    return $functionHover;
                }
                return $this->getBuiltinFunctionHover($node->toString());
            }

            // Static method call: ClassName::method()
            if ($parent instanceof StaticCall) {
                return $this->getClassHover($node);
            }

            // Static property fetch: ClassName::$property
            if ($parent instanceof StaticPropertyFetch) {
                return $this->getClassHover($node);
            }

            // Fall through to class hover for class references
            return $this->getClassHover($node);
        }

        // Identifier node - could be method name, property name, or function call
        if ($node instanceof Identifier) {
            $parent = $node->getAttribute('parent');

            // Method call: $obj->method() or $obj?->method()
            if (MemberAccessResolver::isMethodCall($parent)) {
                /** @var MethodCall|NullsafeMethodCall $parent */
                return $this->getMethodHover($parent, $ast);
            }

            // Static method call: ClassName::method()
            if ($parent instanceof StaticCall) {
                return $this->getStaticMethodHover($parent);
            }

            // Property fetch: $obj->property or $obj?->property
            if (MemberAccessResolver::isPropertyFetch($parent)) {
                /** @var PropertyFetch|NullsafePropertyFetch $parent */
                return $this->getPropertyHover($parent, $ast);
            }

            // Static property fetch: ClassName::$property
            if ($parent instanceof StaticPropertyFetch) {
                return $this->getStaticPropertyHover($parent);
            }

            if ($parent instanceof FuncCall) {
                return $this->getFunctionHover($node->toString(), $ast);
            }
        }

        return null;
    }

    private function getClassHover(Name $node): ?string
    {
        $classNameStr = ScopeFinder::resolveClassName($node);

        $classInfo = $this->classRepository->get(new ClassName($classNameStr));

        if ($classInfo === null) {
            return null;
        }

        return $this->formatClassHover($classInfo);
    }

    private function formatClassHover(ClassInfo $classInfo): string
    {
        $parts = [];

        // Add docblock if present
        if ($classInfo->docblock !== null) {
            $parts[] = DocblockParser::extractDescription($classInfo->docblock);
        }

        // Add signature
        $keyword = match ($classInfo->kind) {
            ClassKind::Interface_ => 'interface',
            ClassKind::Trait_ => 'trait',
            ClassKind::Enum_ => 'enum',
            default => 'class',
        };

        $signature = $keyword . ' ' . $classInfo->name->shortName();

        if ($classInfo->kind === ClassKind::Class_) {
            if ($classInfo->parent !== null) {
                $signature .= ' extends ' . $classInfo->parent->shortName();
            }
            if ($classInfo->interfaces !== []) {
                $implements = array_map(fn($n) => $n->shortName(), $classInfo->interfaces);
                $signature .= ' implements ' . implode(', ', $implements);
            }
        }

        if ($classInfo->kind === ClassKind::Interface_ && $classInfo->interfaces !== []) {
            $extends = array_map(fn($n) => $n->shortName(), $classInfo->interfaces);
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
        $funcNode = ScopeFinder::findFunction($functionName, $ast);
        if ($funcNode === null) {
            return null;
        }

        return $this->formatFunctionHover($funcNode);
    }

    private function formatFunctionHover(Stmt\Function_ $node): string
    {
        $funcInfo = FunctionInfo::fromNode($node);
        $parts = [];

        if ($funcInfo->docblock !== null) {
            $parts[] = DocblockParser::extractDescription($funcInfo->docblock);
        }

        $parts[] = '```php' . "\n" . $funcInfo->format() . "\n```";

        return implode("\n\n", $parts);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getMethodHover(MethodCall|NullsafeMethodCall $call, array $ast): ?string
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $className = $this->memberAccessResolver->resolveObjectClassName($call->var, $ast);
        if ($className === null) {
            return null;
        }

        return $this->getMethodHoverForClass($className->fqn, $methodName->toString());
    }

    private function getStaticMethodHover(StaticCall $call): ?string
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $class = $call->class;
        if (!$class instanceof Name) {
            return null;
        }

        $className = ScopeFinder::resolveClassNameInContext($class, $call);
        if ($className === null) {
            return null;
        }

        return $this->getMethodHoverForClass($className, $methodName->toString());
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getPropertyHover(PropertyFetch|NullsafePropertyFetch $fetch, array $ast): ?string
    {
        $propertyInfo = $this->memberAccessResolver->resolvePropertyFetch($fetch, $ast);
        if ($propertyInfo === null) {
            return null;
        }

        return $this->formatPropertyHover($propertyInfo);
    }

    private function getStaticPropertyHover(StaticPropertyFetch $fetch): ?string
    {
        $propertyInfo = $this->memberAccessResolver->resolveStaticPropertyFetch($fetch);
        if ($propertyInfo === null) {
            return null;
        }

        return $this->formatPropertyHover($propertyInfo);
    }

    /**
     * @param class-string $classNameStr
     */
    private function getMethodHoverForClass(string $classNameStr, string $methodNameStr): ?string
    {
        $className = new ClassName($classNameStr);
        $methodName = new MethodName($methodNameStr);

        // Hover shows all members regardless of caller context
        $methodInfo = $this->memberResolver->findMethod($className, $methodName, Visibility::Private);
        if ($methodInfo === null) {
            return null;
        }

        return $this->formatMethodHover($methodInfo);
    }

    private function formatMethodHover(MethodInfo $method): string
    {
        $parts = [];

        if ($method->docblock !== null) {
            $parts[] = DocblockParser::extractDescription($method->docblock);
        }

        $parts[] = '```php' . "\n" . $method->format(showDefaults: true) . "\n```";

        return implode("\n\n", $parts);
    }

    private function formatPropertyHover(DomainPropertyInfo $property): string
    {
        $parts = [];

        if ($property->docblock !== null) {
            $parts[] = DocblockParser::extractDescription($property->docblock);
        }

        $parts[] = '```php' . "\n" . $property->format() . "\n```";

        return implode("\n\n", $parts);
    }

    private function getBuiltinFunctionHover(string $functionName): ?string
    {
        try {
            $reflection = new ReflectionFunction($functionName);
            return $this->formatReflectionFunction($reflection);
        } catch (ReflectionException) {
            return null;
        }
    }

    private function formatReflectionFunction(ReflectionFunction $func): string
    {
        $funcInfo = FunctionInfo::fromReflection($func);
        $parts = [];

        if ($funcInfo->docblock !== null) {
            $parts[] = DocblockParser::extractDescription($funcInfo->docblock);
        }

        $parts[] = '```php' . "\n" . $funcInfo->format() . "\n```";

        return implode("\n\n", $parts);
    }
}
