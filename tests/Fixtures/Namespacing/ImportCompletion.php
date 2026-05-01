<?php

declare(strict_types=1);

namespace Fixtures\Namespacing\ImportCompletion {

    use Fixtures\Namespacing\Models\User;
    use Fixtures\Namespacing\Models\UserRepository as Repo;

    function triggerImportedClassPartial()
    {
        $x = Us/*|imported_class_partial*/
    }

    function triggerAliasedClassPartial()
    {
        $x = Rep/*|aliased_class_partial*/
    }

}

namespace Fixtures\Namespacing\ImportCompletion\Grouped {

    use Fixtures\Namespacing\Models\{User, Post};

    function triggerGroupedImportPartial()
    {
        $x = Us/*|grouped_import_partial*/
    }

}
