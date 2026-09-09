<?php

declare(strict_types=1);

namespace App\Enums;

enum EmailChallengePurpose: string
{
    case SIGNUP = 'signup';
    case VERIFY_EMAIL = 'verify_email';
    case SIGN_IN = 'sign_in';
    case CONFIRM_IDENTITY = 'confirm_identity';
    case CHANGE_EMAIL = 'change_email';
    case ENABLE_EMAIL_SIGN_IN = 'enable_email_sign_in';

    /**
     * Sign-in and confirmation prove a moment in time and expire quickly.
     * Every other purpose proves ongoing mailbox ownership and gets more
     * realistic delivery slack.
     */
    public function lifetimeMinutes(): int
    {
        return match ($this) {
            self::SIGN_IN, self::CONFIRM_IDENTITY => 10,
            self::SIGNUP, self::VERIFY_EMAIL, self::CHANGE_EMAIL, self::ENABLE_EMAIL_SIGN_IN => 15,
        };
    }
}
