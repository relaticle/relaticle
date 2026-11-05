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
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Relaticle\EmailIntegration\Actions\CancelQueuedEmailAction;
use Relaticle\EmailIntegration\Actions\RetryMailboxHistoryImportFailuresAction;
use Relaticle\EmailIntegration\Data\ConversationRow;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Concerns\HasConversationReader;
use Relaticle\EmailIntegration\Filament\Concerns\HasConversationsFlag;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use RuntimeException;

/**
 * Panel-wide handler for notification clicks: the reader from the bell on pages
 * that host none, and Undo on the queued-send toast.
 *
 * @property-read Email|null $selectedEmail
 * @property-read bool $showsConversations
 * @property-read Collection<int, EmailAccessRequest> $pendingAccessRequests
 */
final class EmailAccessNotificationHandler extends Component implements HasActions, HasSchemas
{
    use HasConversationReader;
    use HasConversationsFlag;
    use HasEmailReaderActions;
    use InteractsWithActions;
    use InteractsWithSchemas;

    public const string LIVEWIRE_ALIAS = 'email-integration.access-request-handler';

    #[Url(as: 'email')]
    public ?string $selectedEmailId = null;

    // The page opens `?email=` in its own reader, and the bell asks it to open the rest.
    #[Locked]
    public bool $hostsReader = false;

    // A record page's Emails tab may not be mounted, so the bell reloads the page through the resolver.
    #[Locked]
    public bool $readerOnRecordPage = false;

    public function mount(): void
    {
        if ($this->selectedEmailId !== null && ! $this->hostsReader) {
            $this->openRequestedEmail($this->selectedEmailId);
        }
    }

    #[On('open-email-from-access-request')]
    public function selectEmail(string $emailId): void
    {
        $this->closeNotificationsPanel();

        if ($this->readerOnRecordPage) {
            $this->redirect(route('email.open', $emailId));

            return;
        }

        if ($this->hostsReader) {
            $this->dispatch('email:open', emailId: $emailId);

            return;
        }

        $this->openRequestedEmail($emailId);
    }

    #[Computed]
    public function showsConversations(): bool
    {
        return $this->conversationsEnabled($this->authUser()->currentWorkspace);
    }

    /** @return list<ConversationRow> */
    protected function conversationRows(): array
    {
        return [];
    }

    private function openRequestedEmail(string $emailId): void
    {
        if ($this->showsConversations()) {
            $this->openLinkedEmail($emailId);

            return;
        }

        $this->openEmailReader($emailId);
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
        if ($this->hostsReader || $this->selectedEmailId === null) {
            return null;
        }

        if ($this->showsConversations()) {
            return $this->conversationCopy($this->selectedEmailId);
        }

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
        $email = $this->selectedEmail;

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
