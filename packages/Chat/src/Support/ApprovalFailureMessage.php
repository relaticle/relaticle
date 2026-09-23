<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final readonly class ApprovalFailureMessage
{
    public static function for(Throwable $exception): string
    {
        // Entity actions abort_unless(..., 403) with no message once the approver lost the role.
        if ($exception instanceof HttpException && $exception->getStatusCode() === 403 && $exception->getMessage() === '') {
            return __('Your role no longer allows this change.');
        }

        return $exception->getMessage();
    }
}
