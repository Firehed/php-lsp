<?php

declare(strict_types=1);

namespace Fixtures\Hover;

use Fixtures\Domain\User;

class UserCollection
{
    /** @var User[] */
    public array $users = [];

    /** @var User[] */
    public static array $shared = [];

    /** @var User[] */
    public const CONFIGURED_USERS = [];

    /**
     * @return User[]
     */
    public function all(): array
    {
        return [];
    }

    /**
     * @return User[]
     */
    public static function loadAll(): array
    {
        return [];
    }
}

/** @return User[] */
function foreachUserProvider(): array
{
    return [];
}

/** @var User[] */
const FOREACH_USER_CONSTANT = [];

/** @return \Fixtures\Domain\User[] */
function foreachUserFqnProvider(): array
{
    return [];
}

/** @return NoSuchClass[] */
function foreachUnknownProvider(): array
{
    return [];
}

class ForeachElement
{
    /** @var User[] */
    private array $userProperty = [];

    public function iterateUsers(UserCollection $users): void
    {
        foreach ($users->all() as $user) {
            $user->getName(); //hover:foreach_member
        }
    }

    public function iteratePropertyFetch(): void
    {
        foreach ($this->userProperty as $user) {
            $user->getName(); //hover:foreach_property_fetch
        }
    }

    public function iterateFuncCall(): void
    {
        foreach (foreachUserProvider() as $user) {
            $user->getName(); //hover:foreach_func_call
        }
    }

    public function iterateFuncCallFqn(): void
    {
        foreach (foreachUserFqnProvider() as $user) {
            $user->getName(); //hover:foreach_func_call_fqn
        }
    }

    public function iterateFuncCallUnknown(): void
    {
        foreach (foreachUnknownProvider() as $item) {
            $item->getName(); //hover:foreach_func_call_unknown
        }
    }

    public function iterateMethodCallOnUnresolvableReceiver(): void
    {
        // $undefined has no declaration, so the receiver of the method call
        // resolves to no class. The foreach source cannot yield an element
        // type; a member call on $item cannot resolve.
        foreach ($undefined->everything() as $item) {
            $item->getName(); //hover:foreach_method_call_unresolvable
        }
    }

    public function iteratePropertyFetchOnUnresolvableReceiver(): void
    {
        foreach ($undefined->everything as $item) {
            $item->getName(); //hover:foreach_property_fetch_unresolvable
        }
    }

    /**
     * The docblock @return has no array element type. The foreach element
     * type falls back to null; a member call on $item cannot resolve.
     *
     * @return \stdClass
     */
    public function iterateNoElementType(): array
    {
        return [];
    }

    public function useNoElementType(): void
    {
        foreach ($this->iterateNoElementType() as $item) {
            $item->getName(); //hover:foreach_no_element_type
        }
    }

    public function iterateStaticCall(): void
    {
        foreach (UserCollection::loadAll() as $user) {
            $user->getName(); //hover:foreach_static_call
        }
    }

    public function iterateNullsafeMethodCall(?UserCollection $collection): void
    {
        foreach ($collection?->all() as $user) {
            $user->getName(); //hover:foreach_nullsafe_method_call
        }
    }

    public function iterateStaticPropertyFetch(): void
    {
        foreach (UserCollection::$shared as $user) {
            $user->getName(); //hover:foreach_static_property_fetch
        }
    }

    public function iterateNullsafePropertyFetch(?UserCollection $collection): void
    {
        foreach ($collection?->users as $user) {
            $user->getName(); //hover:foreach_nullsafe_property_fetch
        }
    }

    public function iterateClassConstFetch(): void
    {
        foreach (UserCollection::CONFIGURED_USERS as $user) {
            $user->getName(); //hover:foreach_class_const_fetch
        }
    }

    public function iterateConstFetch(): void
    {
        foreach (\Fixtures\Hover\FOREACH_USER_CONSTANT as $user) {
            $user->getName(); //hover:foreach_const_fetch
        }
    }
}
