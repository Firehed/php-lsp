<?php

declare(strict_types=1);

namespace Fixtures\Services;

use Fixtures\Attributes\NoConstructorAttribute;
use Fixtures\Attributes\Route;

class ApiController
{
    #[Route('/api/users')] //hover:attr_class
    public function listUsers(): array
    {
        return [];
    }

    #[Route(method: 'POST', path: '/api/posts')] //hover:attr_named_arg
    public function createPost(): array
    {
        return [];
    }

    #[Route(wrongParam: '/api/wrong')] //hover:attr_wrong_param
    public function wrongAttrParam(): array
    {
        return [];
    }

    #[NoConstructorAttribute(someParam: 'value')] //hover:attr_no_constructor
    public function noConstructorAttr(): array
    {
        return [];
    }
}
