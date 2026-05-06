<?php

declare(strict_types=1);

namespace Fixtures\Services;

use Fixtures\Attributes\Route;

class ApiController
{
    #[Route('/api/users')]
    public function listUsers(): array //hover:attribute_usage
    {
        return [];
    }
}
