<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

final class EmailVerificationPrompt extends \Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt
{
    public function hasLogo(): bool
    {
        return false;
    }
}
