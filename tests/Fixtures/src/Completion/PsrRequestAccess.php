<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Psr\Http\Message\RequestInterface;

class PsrRequestAccess
{
    public function accessRequest(RequestInterface $request): void
    {
        $request->/*|psr_request_access*/
    }
}
