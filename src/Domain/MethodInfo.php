<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Metadata about a class method.
 */
final readonly class MethodInfo implements Formattable, MemberInfo, ResolvedCallable
{
    use HasCallableParameters;
    use HasSymbolLocation;


    /**
     * @param list<ParameterInfo> $parameters
     */
    public function __construct(
        public MethodName $name,
        public Visibility $visibility,
        public bool $isStatic,
        public bool $isAbstract,
        public bool $isFinal,
        public array $parameters,
        public ?Type $returnType,
        public ?string $docblock,
        public ?string $file,
        public ?int $line,
        public ClassName $declaringClass,
    ) {
    }

    public function getDeclaringClass(): ClassName
    {
        return $this->declaringClass;
    }

    public function getMemberKind(): MemberKind
    {
        return MemberKind::Method;
    }

    public function getName(): MethodName
    {
        return $this->name;
    }

    public function getReturnType(): ?Type
    {
        return $this->returnType;
    }

    /**
     * ResolvedSymbol's value type for a method is its return type.
     */
    public function getType(): ?Type
    {
        return $this->returnType;
    }

    public function format(bool $showDefaults = true): string
    {
        $parts = [$this->visibility->format()];
        if ($this->isStatic) {
            $parts[] = 'static';
        }
        if ($this->isAbstract) {
            $parts[] = 'abstract';
        }
        if ($this->isFinal) {
            $parts[] = 'final';
        }
        $parts[] = 'function';

        $params = array_map(fn($p) => $p->format(showDefault: $showDefaults), $this->parameters);
        $parts[] = $this->name->name . '(' . implode(', ', $params) . ')';

        $sig = implode(' ', $parts);
        if ($this->returnType !== null) {
            $sig .= ': ' . $this->returnType->format();
        }
        return $sig;
    }

    public function getVisibility(): Visibility
    {
        return $this->visibility;
    }

    public function isStatic(): bool
    {
        return $this->isStatic;
    }
}
