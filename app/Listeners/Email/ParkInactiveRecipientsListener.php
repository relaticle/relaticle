<?php

declare(strict_types=1);

namespace App\Listeners\Email;

use App\Events\InactiveRecipientsRejected;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final class ParkInactiveRecipientsListener
{
    public function handle(InactiveRecipientsRejected $event): void
    {
        User::query()->withEmailIn($event->addresses)->update(['email_bounced_at' => now()]);

        Log::info('Mail provider rejected an inactive recipient.', ['recipients' => $event->addresses]);
    }
}
