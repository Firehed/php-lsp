<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Document\TextDocument;
use PhpParser\Node;
use PhpParser\Node\Stmt;

final class SymbolExtractor
{
    public function __construct(
        private readonly DeclarationScanner $declarations = new DeclarationScanner(),
    ) {
    }

    /**
     * @param array<Stmt> $ast
     * @return list<Symbol>
     */
    public function extract(TextDocument $document, array $ast): array
    {
        $declarations = $this->declarations->scan($ast);
        $symbols = [];

        foreach ($declarations->classLikes as $declaration) {
            $fqn = $declaration->name->fullyQualifiedName();
            $symbols[] = new Symbol(
                name: $declaration->name->shortName,
                fullyQualifiedName: $fqn,
                kind: self::kindOf($declaration->node),
                location: self::locate($document, $declaration->node),
            );

            foreach ($declaration->node->getMethods() as $method) {
                $name = $method->name->toString();
                $symbols[] = new Symbol(
                    name: $name,
                    fullyQualifiedName: $fqn . '::' . $name,
                    kind: SymbolKind::Method,
                    location: self::locate($document, $method),
                    containerName: $declaration->name->shortName,
                );
            }
        }

        foreach ($declarations->functions as $declaration) {
            $symbols[] = new Symbol(
                name: $declaration->name->shortName,
                fullyQualifiedName: $declaration->name->fullyQualifiedName(),
                kind: SymbolKind::Function_,
                location: self::locate($document, $declaration->node),
            );
        }

        foreach ($declarations->constants as $declaration) {
            $symbols[] = new Symbol(
                name: $declaration->name->shortName,
                fullyQualifiedName: $declaration->name->fullyQualifiedName(),
                kind: SymbolKind::Constant,
                location: self::locate($document, $declaration->node),
            );
        }

        // Callers index `$symbols[0]` as the file's first declaration, which the two
        // separate scanner lists would otherwise interleave by kind rather than by
        // where each is written.
        usort($symbols, self::byPosition(...));

        return $symbols;
    }

    private static function byPosition(Symbol $a, Symbol $b): int
    {
        return [$a->location->startLine, $a->location->startCharacter]
            <=> [$b->location->startLine, $b->location->startCharacter];
    }

    private static function kindOf(Stmt\ClassLike $node): SymbolKind
    {
        return match (true) {
            $node instanceof Stmt\Enum_ => SymbolKind::Enum_,
            $node instanceof Stmt\Interface_ => SymbolKind::Interface_,
            $node instanceof Stmt\Trait_ => SymbolKind::Trait_,
            default => SymbolKind::Class_,
        };
    }

    private static function locate(TextDocument $document, Node $node): Location
    {
        return new Location(
            uri: $document->uri,
            startLine: $node->getStartLine() - 1, // LSP is 0-indexed
            startCharacter: $document->positionAt($node->getStartFilePos())['character'],
            endLine: $node->getEndLine() - 1,
            endCharacter: $document->positionAt($node->getEndFilePos() + 1)['character'],
        );
    }
}
