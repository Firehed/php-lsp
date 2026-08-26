<?php

declare(strict_types=1);

namespace Fixtures\Namespacing\ImportCompletion {

    use Fixtures\Namespacing\Models\User;
    use Fixtures\Namespacing\Models\UserRepository as Repo;
    use Fixtures\Traits\SingletonTrait;
    use function Fixtures\Namespacing\Models\makeUser;
    use const Fixtures\Namespacing\Models\DEFAULT_LIMIT;

    function triggerImportedClassPartial()
    {
        $x = Us/*|imported_class_partial*/
    }

    function triggerImportedFunctionPartial()
    {
        $x = make/*|imported_function_partial*/
    }

    function triggerImportedConstantPartial()
    {
        $x = DEF/*|imported_constant_partial*/
    }

    function triggerAliasedClassPartial()
    {
        $x = Rep/*|aliased_class_partial*/
    }

    function triggerTraitInExpression()
    {
        $x = Sing/*|trait_expression_partial*/
    }

    function triggerTypeHintReturn(): /*|type_hint_return*/
    {
    }

}

namespace Fixtures\Namespacing\ImportCompletion\Grouped {

    use Fixtures\Namespacing\Models\{User, Post, UserRepository as Repos};

    function triggerGroupedImportPartial()
    {
        $x = Us/*|grouped_import_partial*/
    }

}

namespace Fixtures\Namespacing\ImportCompletion\Collision {

    use Fixtures\Namespacing\Models\Widget;
    use function Fixtures\Namespacing\Helpers\Widget;

    function triggerCollidingPartial()
    {
        $x = Widg/*|colliding_partial*/
    }

}

namespace Fixtures\Namespacing\ImportCompletion\MixedGroup {

    use Fixtures\Namespacing\Models\{
        UserRepository,
        function makeUser,
        const DEFAULT_LIMIT,
    };

    function triggerMixedGroupPartial()
    {
        $x = User/*|mixed_group_partial*/
    }

}
