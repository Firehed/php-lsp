<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\TextSymbolExtractor;

/**
 * The default {@see TextSymbolExtractor}: the third `DeclaredSymbol` producer
 * (RFC 1 §5.3), alongside the AST- and reflection-based paths. Regex lives in
 * {@see TextFallbackHelper} — this class asks for match structs and assembles a
 * `ClassInfo` from them.
 */
final class DefaultTextSymbolExtractor implements TextSymbolExtractor
{
    public function __construct(
        private readonly TextFallbackHelper $text = new TextFallbackHelper(),
    ) {
    }

    /**
     * @return list<DeclaredSymbol>
     */
    public function extract(string $content, string $filePath): array
    {
        $declarations = $this->text->findClassLikeDeclarations($content);
        if ($declarations === []) {
            return [];
        }

        $lines = explode("\n", $content);
        // Namespace and imports can sit anywhere in the file; scan the whole document.
        $context = NameContextFactory::fromText($lines, count($lines) - 1);
        $namespace = $context->namespace;

        $symbols = [];
        $seen = [];
        foreach ($declarations as $declaration) {
            $fqn = $namespace === '' ? $declaration['name'] : $namespace . '\\' . $declaration['name'];
            $qualified = QualifiedName::fromFullyQualified($fqn);
            $key = NameKind::ClassLike->keyFor($qualified);
            if (array_key_exists($key, $seen)) {
                continue;
            }
            $seen[$key] = true;

            $className = TypeFactory::className($fqn);
            $parent = $declaration['extends'] === null
                ? null
                : TypeFactory::className($context->candidates($declaration['extends'], NameKind::ClassLike)[0]);

            $info = $this->buildClassInfo(
                $declaration['body'],
                $className,
                self::kindFor($declaration['keyword']),
                $parent,
                $filePath,
            );
            $symbols[] = new DeclaredSymbol($qualified, NameKind::ClassLike, $info);
        }

        return $symbols;
    }

    private function buildClassInfo(
        string $body,
        ClassName $name,
        ClassKind $kind,
        ?ClassName $parent,
        string $filePath,
    ): ClassInfo {
        return new ClassInfo(
            name: $name,
            kind: $kind,
            isAbstract: false,
            isFinal: false,
            isReadonly: false,
            isAttribute: false,
            parent: $parent,
            interfaces: [],
            traits: [],
            methods: $this->methods($body, $name),
            properties: $this->properties($body, $name),
            constants: $this->constants($body, $name),
            enumCases: [],
            docblock: null,
            file: $filePath,
            line: null,
        );
    }

    /**
     * @return array<string, MethodInfo>
     */
    private function methods(string $body, ClassName $className): array
    {
        $methods = [];
        foreach ($this->text->matchMethodsInBody($body) as $match) {
            $methods[$match['name']] = new MethodInfo(
                name: new MethodName($match['name']),
                visibility: Visibility::fromString($match['visibility']),
                isStatic: $match['isStatic'],
                isAbstract: false,
                isFinal: false,
                parameters: [],
                returnType: null,
                declaringClass: $className,
                docblock: null,
                file: null,
                line: null,
            );
        }
        return $methods;
    }

    /**
     * @return array<string, PropertyInfo>
     */
    private function properties(string $body, ClassName $className): array
    {
        $properties = [];
        foreach ($this->text->matchPropertiesInBody($body) as $match) {
            $properties[$match['name']] = new PropertyInfo(
                name: new PropertyName($match['name']),
                visibility: Visibility::fromString($match['visibility']),
                isStatic: $match['isStatic'],
                isReadonly: $match['isReadonly'],
                isPromoted: false,
                type: null,
                docblock: null,
                file: null,
                line: null,
                declaringClass: $className,
            );
        }
        return $properties;
    }

    /**
     * @return array<string, ConstantInfo>
     */
    private function constants(string $body, ClassName $className): array
    {
        $constants = [];
        foreach ($this->text->matchConstantsInBody($body) as $match) {
            $constants[$match['name']] = new ConstantInfo(
                name: new ConstantName($match['name']),
                visibility: $match['visibility'] === ''
                    ? Visibility::Public
                    : Visibility::fromString($match['visibility']),
                isFinal: false,
                type: null,
                docblock: null,
                file: null,
                line: null,
                declaringClass: $className,
            );
        }
        return $constants;
    }

    private static function kindFor(string $keyword): ClassKind
    {
        return match ($keyword) {
            'interface' => ClassKind::Interface_,
            'trait' => ClassKind::Trait_,
            'enum' => ClassKind::Enum_,
            default => ClassKind::Class_,
        };
    }
}
