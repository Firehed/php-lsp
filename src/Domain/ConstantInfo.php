<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Metadata about a constant — either a class constant (when declaringClass is
 * set) or a global constant (when declaringClass is null).
 *
 * One type serves both (Plan 0002 §5.3): the nullable is a conscious exception
 * to the no-nullable rule, since the alternatives are a second metadata type
 * differing in one field, or a sentinel ClassName the type system cannot catch
 * as a lie.
 */
final readonly class ConstantInfo implements Formattable, MemberInfo, SymbolInfo
{
    use HasSymbolLocation;


    public function __construct(
        public ConstantName $name,
        public Visibility $visibility,
        public bool $isFinal,
        public ?Type $type,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
        public ?ClassName $declaringClass = null,
    ) {
    }

    /**
     * Build from a global constant declaration: a `const` declarator or a
     * literal-name `define()` call.
     *
     * @param Node\Const_|Expr\FuncCall $node the declaring node
     * @param string $shortName the constant's short name (already extracted by
     *        the scanner, so the factory does not re-derive it)
     */
    public static function fromGlobalDeclaration(
        Node\Const_|Expr\FuncCall $node,
        string $shortName,
        ?string $file = null,
    ): self {
        return new self(
            name: new ConstantName($shortName),
            visibility: Visibility::Public,
            isFinal: true,
            type: null,
            docblock: $node->getDocComment()?->getText(),
            file: $file,
            line: $node->getStartLine(),
            declaringClass: null,
        );
    }

    public function format(): string
    {
        if ($this->declaringClass === null) {
            return $this->formatGlobal();
        }

        return $this->formatClassConstant();
    }

    private function formatClassConstant(): string
    {
        $parts = [$this->visibility->format()];
        if ($this->isFinal) {
            $parts[] = 'final';
        }
        $parts[] = 'const';
        if ($this->type !== null) {
            $parts[] = $this->type->format();
        }
        $parts[] = $this->name->name;
        return implode(' ', $parts);
    }

    private function formatGlobal(): string
    {
        $parts = ['const', $this->name->name];
        if ($this->type !== null) {
            array_splice($parts, 1, 0, [$this->type->format()]);
        }
        return implode(' ', $parts);
    }

    /**
     * A class constant's declaring class. Fails on a global constant, matching
     * how the resolver hands one out only for the class-constant lookup path;
     * the two shapes stay in one metadata type (§5.3), and the assertion is the
     * type system's stand-in for the invariant the resolver enforces.
     */
    public function getDeclaringClass(): ClassName
    {
        assert($this->declaringClass !== null, 'getDeclaringClass() is only defined for class constants');
        return $this->declaringClass;
    }

    public function getMemberKind(): MemberKind
    {
        return MemberKind::Constant;
    }

    public function getName(): ConstantName
    {
        return $this->name;
    }

    public function getType(): ?Type
    {
        return $this->type;
    }

    public function getVisibility(): Visibility
    {
        return $this->visibility;
    }

    /**
     * A class constant is reached on the class, never on an instance.
     */
    public function isStatic(): bool
    {
        return true;
    }
}
