<?php

declare(strict_types=1);

namespace Fixtures\Exception;

use RuntimeException;

/**
 * Reaches Throwable transitively: AppException -> RuntimeException -> Exception -> Throwable.
 */
class AppException extends RuntimeException implements ExceptionInterface
{
}
