<?php

declare(strict_types=1);

namespace Fixtures\Namespacing\Models {

    class User
    {
        public const STATUS_ACTIVE = 'active';

        public static function findById(int $id): self
        {
            return new self();
        }
    }

    class UserModel
    {
        public const ROLE_ADMIN = 'admin';

        public static function create(): self
        {
            return new self();
        }
    }

    class Post
    {
        public const STATUS_DRAFT = 'draft';
    }

    class UserRepository
    {
        public function find(int $id): ?User
        {
            return null;
        }
    }

    const DEFAULT_LIMIT = 25;

    function makeUser(): User
    {
        return new User();
    }

    class Widget
    {
        public function __construct(public readonly string $size)
        {
        }
    }

}

namespace Fixtures\Namespacing\Helpers {

    // Shares its short name with Models\Widget, and deliberately sits in another
    // namespace so the two imports do not resolve to the same FQN.
    function Widget(): string
    {
        return 'widget';
    }

}

namespace Fixtures\Namespacing\Controllers {

    use Fixtures\Namespacing\Models\User;

    class UserController
    {
        public function triggerImportedStatic(): void
        {
            User::/*|imported_static*/
        }
    }

}

namespace Fixtures\Namespacing\Controllers\Aliased {

    use Fixtures\Namespacing\Models\UserModel as User;

    class AliasedController
    {
        public function triggerAliasedStatic(): void
        {
            User::/*|aliased_static*/
        }
    }

}
