<?php

declare(strict_types=1);

namespace Fixtures\Services;

use Fixtures\Attributes\NoConstructorAttribute;
use Fixtures\Attributes\Route;

class ApiController
{
    #[Route('/api/users')]
    public function listUsers(): array
    {
        return [];
    }

    #[Route(path: '/api/posts', method: 'POST')]
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
