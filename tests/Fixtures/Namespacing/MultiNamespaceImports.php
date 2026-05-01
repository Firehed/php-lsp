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
