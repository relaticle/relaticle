<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Illuminate\Validation\ValidationException;
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

        if ($exception instanceof ValidationException) {
            return self::forValidation($exception);
        }

        return $exception->getMessage();
    }

    private static function forValidation(ValidationException $exception): string
    {
        $fields = array_keys($exception->errors());

        return match (true) {
            in_array('connected_account_id', $fields, true) => __('This mailbox is no longer connected, so nothing was sent or saved.'),
            in_array('in_reply_to_email_id', $fields, true) => __('The email this replies to is no longer visible to you.'),
            default => $exception->getMessage(),
        };
    }
}
