<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\CrmEntity;
use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationReason;
use App\Enums\Notifications\NotificationType;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\BindWorkspace;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;

#[DeleteWhenMissingModels]
#[Tries(3)]
#[Backoff(60, 300)]
abstract class InboxNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    abstract public function workspace(): Workspace;

    abstract public function subject(): Model;

    abstract public function reason(): NotificationReason;

    /** @return array<string, mixed> */
    abstract public function facts(): array;

    abstract public static function present(NotificationRow $row): FilamentNotification;

    /** @return list<string> */
    public static function presentingRelations(): array
    {
        return [];
    }

    public function actor(): ?User
    {
        return null;
    }

    public function groupKey(): ?string
    {
        return null;
    }

    public function preference(): ?NotificationType
    {
        return null;
    }

    public function visibleInEveryWorkspace(): bool
    {
        return false;
    }

    public function collapsesReadRows(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    public function mergeFacts(array $existing): array
    {
        return $this->facts();
    }

    /** @return list<BindWorkspace> */
    public function middleware(): array
    {
        return [new BindWorkspace((string) $this->workspace()->getKey())];
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return method_exists($this, 'toMail') ? ['database', 'mail'] : ['database'];
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        $actor = $this->actor();

        if ($actor instanceof User && $actor->is($notifiable)) {
            return false;
        }

        if (! $notifiable->belongsToWorkspaceId((string) $this->workspace()->getKey())) {
            return false;
        }

        $subject = $this->subject();

        if (CrmEntity::tryFromModel($subject) instanceof CrmEntity && $notifiable->cannot('view', $subject)) {
            return false;
        }

        return $this->allowedByPreference($notifiable, $channel);
    }

    /** @return array<string, mixed> */
    final public function toDatabase(User $notifiable): array
    {
        return $this->facts();
    }

    private function allowedByPreference(User $notifiable, string $channel): bool
    {
        $type = $this->preference();

        $preferenceChannel = match ($channel) {
            'database' => NotificationChannel::InApp,
            'mail' => NotificationChannel::Email,
            default => null,
        };

        if (! $type instanceof NotificationType || $preferenceChannel === null) {
            return true;
        }

        return $notifiable->wantsNotification($type, $preferenceChannel);
    }
}
