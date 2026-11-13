<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\User;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Support\Facades\DB;

final readonly class RefreshNotificationBell
{
    public function execute(User $user): void
    {
        DB::afterCommit(static function () use ($user): void {
            event(new DatabaseNotificationsSent($user));
        });
    }
}
