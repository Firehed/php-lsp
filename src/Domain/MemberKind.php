<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * A category of class-like member.
 *
 * RFC 1 Appendix A lists member kind as an axis switched in exactly one place;
 * this enum is that place. Everything the hierarchy walk in
 * {@see \Firehed\PhpLsp\Repository\MemberResolver} needs that varies by kind is
 * answered here, so a new kind is a case in this file rather than another pair
 * of walks.
 */
enum MemberKind
{
    case Constant;

    case EnumCase;

    case Method;

    case Property;

    public function isMethod(): bool
    {
        return $this === self::Method;
    }

    /**
     * Where a class-like holds the members of this kind, keyed by declared name.
     *
     * @return array<string, MemberInfo>
     */
    public function membersOf(ClassInfo $classInfo): array
    {
        return match ($this) {
            self::Constant => $classInfo->constants,
            self::EnumCase => $classInfo->enumCases,
            self::Method => $classInfo->methods,
            self::Property => $classInfo->properties,
        };
    }

    /**
     * A member's identity within a class-like: its kind, plus its name under
     * that kind's case rule. Two members collide only when both match.
     */
    public function keyFor(string $name): string
    {
        return $this->name . ':' . $this->normalize($name);
    }

    /**
     * The name as a lookup key. PHP matches method names case-insensitively;
     * every other member kind is matched letter-for-letter.
     */
    public function normalize(string $name): string
    {
        $rule = $this === self::Method ? NameCase::Insensitive : NameCase::Sensitive;

        return $rule->normalize($name);
    }
}
