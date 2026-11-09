<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Profile\DestroyOtherBrowserSessions;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;

final readonly class EndSessionsOnPasswordResetListener
{
    public function __construct(private DestroyOtherBrowserSessions $destroyOtherBrowserSessions) {}

    public function handle(PasswordReset $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->destroyOtherBrowserSessions->execute($event->user, session()->getId());
    }
}
