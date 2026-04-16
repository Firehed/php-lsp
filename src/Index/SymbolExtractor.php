<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Document\TextDocument;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

final class SymbolExtractor extends NodeVisitorAbstract
{
    /** @var list<Symbol> */
    private array $symbols = [];
    private string $uri = '';
    private string $namespace = '';
    private ?string $currentClass = null;
    private TextDocument $document;

    /**
     * @param array<Stmt> $ast
     * @return list<Symbol>
     */
    public function extract(TextDocument $document, array $ast): array
    {
        $this->symbols = [];
        $this->uri = $document->uri;
        $this->namespace = '';
        $this->currentClass = null;
        $this->document = $document;

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this);
        $traverser->traverse($ast);

        return $this->symbols;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = $node->name?->toString() ?? '';
            return null;
        }

        if ($node instanceof Stmt\Class_) {
            $this->addClassLikeSymbol($node, SymbolKind::Class_);
            $this->currentClass = $node->name?->toString();
            return null;
        }

        if ($node instanceof Stmt\Interface_) {
            $this->addClassLikeSymbol($node, SymbolKind::Interface_);
            $this->currentClass = $node->name?->toString();
            return null;
        }

        if ($node instanceof Stmt\Trait_) {
            $this->addClassLikeSymbol($node, SymbolKind::Trait_);
            $this->currentClass = $node->name?->toString();
            return null;
        }

        if ($node instanceof Stmt\Enum_) {
            $this->addClassLikeSymbol($node, SymbolKind::Enum_);
            $this->currentClass = $node->name?->toString();
            return null;
        }

        if ($node instanceof Stmt\Function_) {
            $name = $node->name->toString();
            $fqn = $this->namespace !== '' ? $this->namespace . '\\' . $name : $name;
            $this->symbols[] = new Symbol(
                name: $name,
                fullyQualifiedName: $fqn,
                kind: SymbolKind::Function_,
                location: $this->createLocation($node),
            );
            return null;
        }

        if ($node instanceof Stmt\ClassMethod && $this->currentClass !== null) {
            $name = $node->name->toString();
            $fqn = ($this->namespace !== '' ? $this->namespace . '\\' : '')
                . $this->currentClass . '::' . $name;
            $this->symbols[] = new Symbol(
                name: $name,
                fullyQualifiedName: $fqn,
                kind: SymbolKind::Method,
                location: $this->createLocation($node),
                containerName: $this->currentClass,
            );
            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if (
            $node instanceof Stmt\Class_
            || $node instanceof Stmt\Interface_
            || $node instanceof Stmt\Trait_
            || $node instanceof Stmt\Enum_
        ) {
            $this->currentClass = null;
        }

        return null;
    }

    private function addClassLikeSymbol(
        Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_ $node,
        SymbolKind $kind,
    ): void {
        $name = $node->name?->toString();
        if ($name === null) {
            return; // Anonymous class
        }

        $fqn = $this->namespace !== '' ? $this->namespace . '\\' . $name : $name;
        $this->symbols[] = new Symbol(
            name: $name,
            fullyQualifiedName: $fqn,
            kind: $kind,
            location: $this->createLocation($node),
        );
    }

    private function createLocation(Node $node): Location
    {
        $startLine = $node->getStartLine() - 1; // LSP is 0-indexed
        $endLine = $node->getEndLine() - 1;

        // Get character positions from the document
        $startPos = $this->document->positionAt($node->getStartFilePos());
        $endPos = $this->document->positionAt($node->getEndFilePos() + 1);

        return new Location(
            uri: $this->uri,
            startLine: $startLine,
            startCharacter: $startPos['character'],
            endLine: $endLine,
            endCharacter: $endPos['character'],
        );
    }
}
