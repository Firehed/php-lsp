<?php

declare(strict_types=1);

namespace Fixtures\Services;

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
}
