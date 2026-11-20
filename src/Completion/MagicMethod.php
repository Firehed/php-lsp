<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\Visibility;

/**
 * The methods PHP gives a meaning to, per the manual's "Magic Methods".
 */
enum MagicMethod: string
{
    case Call = '__call';
    case CallStatic = '__callStatic';
    case Clone = '__clone';
    case Construct = '__construct';
    case DebugInfo = '__debugInfo';
    case Destruct = '__destruct';
    case Get = '__get';
    case Invoke = '__invoke';
    case Isset = '__isset';
    case Serialize = '__serialize';
    case Set = '__set';
    case SetState = '__set_state';
    case Sleep = '__sleep';
    case ToString = '__toString';
    case Unserialize = '__unserialize';
    case Unset = '__unset';
    case Wakeup = '__wakeup';

    /**
     * Whether a method declared with these modifiers in this kind of
     * class-like may be this one. An enum allows only three magic methods;
     * the rest must be public unless they construct, destroy, or clone.
     */
    public function declarableAs(ClassKind $kind, bool $static, Visibility $visibility): bool
    {
        $allowedInEnum = in_array($this, [self::Call, self::CallStatic, self::Invoke], true);
        $isStatic = in_array($this, [self::CallStatic, self::SetState], true);
        $mayBeNonPublic = in_array($this, [self::Clone, self::Construct, self::Destruct], true);

        return ($kind !== ClassKind::Enum_ || $allowedInEnum)
            && $static === $isStatic
            && ($visibility === Visibility::Public || $mayBeNonPublic);
    }

    /**
     * The name and the signature the manual gives it. What a constructor or
     * `__invoke` takes is the author's choice, so they take nothing here.
     */
    public function signature(): string
    {
        return match ($this) {
            self::Call => '__call(string $name, array $arguments): mixed',
            self::CallStatic => '__callStatic(string $name, array $arguments): mixed',
            self::Clone => '__clone(): void',
            self::Construct => '__construct()',
            self::DebugInfo => '__debugInfo(): array',
            self::Destruct => '__destruct()',
            self::Get => '__get(string $name): mixed',
            self::Invoke => '__invoke(): mixed',
            self::Isset => '__isset(string $name): bool',
            self::Serialize => '__serialize(): array',
            self::Set => '__set(string $name, mixed $value): void',
            self::SetState => '__set_state(array $properties): object',
            self::Sleep => '__sleep(): array',
            self::ToString => '__toString(): string',
            self::Unserialize => '__unserialize(array $data): void',
            self::Unset => '__unset(string $name): void',
            self::Wakeup => '__wakeup(): void',
        };
    }
}
