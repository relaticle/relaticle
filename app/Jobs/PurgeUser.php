<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Log;
use Laravel\Jetstream\Contracts\DeletesUsers;

#[DeleteWhenMissingModels]
#[Timeout(300)]
#[Tries(2)]
#[UniqueFor(900)]
final class PurgeUser implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly User $user)
    {
        $this->onQueue('imports');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->user->getKey();
    }

    public function handle(DeletesUsers $deletesUsers): void
    {
        if (! $this->user->isDeletionDue()) {
            return;
        }

        $deletesUsers->delete($this->user);

        Log::info('Purged user account', ['user_id' => $this->user->getKey(), 'email' => $this->user->email]);
    }
}
