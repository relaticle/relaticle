<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use App\Models\User;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Relaticle\EmailIntegration\Actions\CancelQueuedEmailAction;
use Relaticle\EmailIntegration\Actions\RetryMailboxHistoryImportFailuresAction;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use RuntimeException;

/**
 * Panel-wide handler for notification clicks: the Emails-tab reader overlay
 * from the bell, and Undo on the queued-send toast. It is the same
 * `selectedEmailId` overlay as the person Emails tab, not a Filament
 * ViewAction modal.
 *
 * @property-read Email|null $selectedEmail
 * @property-read Collection<int, EmailAccessRequest> $pendingAccessRequests
 */
final class EmailAccessNotificationHandler extends Component implements HasActions, HasSchemas
{
    use HasEmailReaderActions;
    use InteractsWithActions;
    use InteractsWithSchemas;

    public const string LIVEWIRE_ALIAS = 'email-integration.access-request-handler';

    #[Url(as: 'email')]
    public ?string $selectedEmailId = null;

    public function mount(): void
    {
        if ($this->selectedEmailId !== null) {
            $this->openEmailReader($this->selectedEmailId);
        }
    }

    #[On('open-email-from-access-request')]
    public function selectEmail(string $emailId): void
    {
        $this->closeNotificationsPanel();

        $this->openEmailReader($emailId);
    }

    /**
     * Covers selection changes that come from the client (the mobile back button
     * clears it) rather than from {@see deselectEmail()}.
     */
    public function updatedSelectedEmailId(): void
    {
        $this->dispatch('composer:dismiss-inline');
    }

    public function deselectEmail(): void
    {
        $this->selectedEmailId = null;
        unset($this->selectedEmail);

        $this->dispatch('composer:dismiss-inline');
    }

    #[Computed]
    public function selectedEmail(): ?Email
    {
        $email = $this->resolveWorkspaceEmail($this->selectedEmailId, 'viewBody');

        if (! $email instanceof Email) {
            return null;
        }

        $email->load(['body', 'participants', 'labels', 'attachments', 'from']);

        return $email;
    }

    /**
     * @return Collection<int, EmailAccessRequest>
     */
    #[Computed]
    public function pendingAccessRequests(): Collection
    {
        $email = $this->selectedEmail();

        if (! $email instanceof Email || $email->user_id !== $this->authUser()->getKey()) {
            return collect();
        }

        return EmailAccessRequest::query()
            ->with('requester')
            ->where('email_id', $email->getKey())
            ->open()
            ->get();
    }

    #[On('retry-mailbox-history-import')]
    public function retryMailboxHistoryImport(string $accountId, string $batchId): void
    {
        $user = $this->authUser();

        $account = ConnectedAccount::query()
            ->whereKey($accountId)
            ->where('user_id', $user->getKey())
            ->first();

        if (! $account instanceof ConnectedAccount) {
            return;
        }

        $queued = resolve(RetryMailboxHistoryImportFailuresAction::class)->execute($user, $account, $batchId);

        $this->closeNotificationsPanel();
        $this->refreshDatabaseNotifications();

        if ($queued) {
            Notification::make()
                ->title(__('filament/notifications/mailbox-import-complete.retry.queued.title'))
                ->body(__('filament/notifications/mailbox-import-complete.retry.queued.body'))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('filament/notifications/mailbox-import-complete.retry.unavailable.title'))
            ->body(__('filament/notifications/mailbox-import-complete.retry.unavailable.body'))
            ->danger()
            ->send();
    }

    #[On('undo-queued-send')]
    public function undoQueuedSend(string $emailId): void
    {
        $user = $this->authUser();

        $email = Email::query()
            ->whereKey($emailId)
            ->where('user_id', $user->getKey())
            ->first();

        if (! $email instanceof Email) {
            Notification::make()
                ->title(__('filament/concerns/email-compose.notifications.not_found.title'))
                ->danger()
                ->send();

            return;
        }

        try {
            resolve(CancelQueuedEmailAction::class)->execute($email);
            $this->dispatch('outbox:changed');
            $this->notifyCancelled();
        } catch (RuntimeException) {
            $email->refresh()->status === EmailStatus::CANCELLED
                ? $this->notifyCancelled()
                : $this->notifyTooLate();
        }
    }

    protected function afterOwnedReaderAccessRequestDecided(bool $approved): void
    {
        $this->deselectEmail();
        $this->closeNotificationsPanel();
        $this->notifyOwnedReaderAccessRequestDecision($approved);
        $this->refreshDatabaseNotifications();
    }

    private function closeNotificationsPanel(): void
    {
        $this->dispatch('inbox:close');
    }

    private function notifyCancelled(): void
    {
        Notification::make()
            ->title(__('filament/concerns/email-compose.notifications.cancelled.title'))
            ->success()
            ->send();
    }

    private function notifyTooLate(): void
    {
        Notification::make()
            ->title(__('filament/concerns/email-compose.notifications.too_late.title'))
            ->danger()
            ->send();
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }

    public function render(): View
    {
        return view('email-integration::livewire.email-access-notification-handler');
    }
}
