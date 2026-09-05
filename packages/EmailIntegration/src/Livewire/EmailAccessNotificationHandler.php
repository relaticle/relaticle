<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use App\Models\User;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Js;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Relaticle\EmailIntegration\Actions\MarkEmailAsReadAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

/**
 * Panel-wide handler so the Emails-tab reader overlay can open from the bell on
 * any page. It is the same `selectedEmailId` overlay as the person Emails tab,
 * not a Filament ViewAction modal.
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

    private const string DATABASE_NOTIFICATIONS_MODAL_ID = 'database-notifications';

    public ?string $selectedEmailId = null;

    #[On('open-email-from-access-request')]
    public function selectEmail(string $emailId): void
    {
        $this->closeNotificationsPanel();

        $this->selectedEmailId = $emailId;

        $this->dispatch('composer:dismiss-inline');
        $this->dispatch('composer:resume-draft', emailId: $emailId);

        resolve(MarkEmailAsReadAction::class)->execute($emailId, $this->authUser());
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
        $email = $this->resolveTeamEmail($this->selectedEmailId, 'view');

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
            ->where('status', EmailAccessRequestStatus::PENDING)
            ->get();
    }

    #[On('approve-email-access-request')]
    public function approveFromNotification(string $requestId): void
    {
        $this->decideOwnedReaderAccessRequest($requestId, approve: true);
    }

    #[On('deny-email-access-request')]
    public function denyFromNotification(string $requestId): void
    {
        $this->decideOwnedReaderAccessRequest($requestId, approve: false);
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
        $this->dispatch('close-modal', id: self::DATABASE_NOTIFICATIONS_MODAL_ID);

        // Livewire's default dispatch targets this component's element. Filament's
        // slide-over listens on window, so close it there as well.
        $this->js(
            'window.dispatchEvent(new CustomEvent("close-modal", { bubbles: true, detail: '.Js::from(['id' => self::DATABASE_NOTIFICATIONS_MODAL_ID]).' }))',
        );
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
