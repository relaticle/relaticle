<?php

declare(strict_types=1);

namespace App\Listeners\Email;

use App\Jobs\Email\SyncSubscriberJob;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

final class NewSubscriberListener
{
    public function handle(Verified $event): void
    {
        /** @var User $user */
        $user = $event->user;

        dispatch(new SyncSubscriberJob((string) $user->id))->afterCommit();
    }
}
