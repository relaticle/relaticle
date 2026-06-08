<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class RegistrationByInvitationOnlyException extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('Registration is by invitation only. Please ask a team administrator to invite you.'));
    }
}
