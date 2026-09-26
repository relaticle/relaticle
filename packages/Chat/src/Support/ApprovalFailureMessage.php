<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final readonly class ApprovalFailureMessage
{
    public static function for(Throwable $exception): string
    {
        // Entity actions abort_unless(..., 403) with no message: a lost role, or an unverified email.
        if ($exception instanceof HttpException && $exception->getStatusCode() === 403 && $exception->getMessage() === '') {
            return __('You no longer have permission to make this change.');
        }

        return $exception->getMessage();
    }
}
