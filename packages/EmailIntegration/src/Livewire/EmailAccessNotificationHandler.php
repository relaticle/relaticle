<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;
use Livewire\Component;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

/**
 * Panel-wide handler for email access request notifications. Mounted once in the
 * app panel body so the shared email reader modal can open from the bell on any
 * page without navigating away.
 */
final class EmailAccessNotificationHandler extends Component implements HasActions, HasSchemas
{
    use HasEmailReaderActions;
    use InteractsWithActions;
    use InteractsWithSchemas;

    public const string LIVEWIRE_ALIAS = 'email-integration.access-request-handler';

    #[On('open-email-from-access-request')]
    public function openEmailFromAccessRequest(string $emailId): void
    {
        $this->mountAction('view', ['emailId' => $emailId]);
    }

    #[On('approve-email-access-request')]
    public function approveFromNotification(string $requestId): void
    {
        $accessRequest = $this->ownedPendingRequest($requestId);

        if ($accessRequest === null) {
            return;
        }

        resolve(ApproveEmailAccessRequestAction::class)->execute($accessRequest, $this->authUser());

        Notification::make()
            ->success()
            ->title(__('filament/pages/email-access-requests.notifications.approved'))
            ->send();

        $this->unmountEmailReaderIfOpen();
    }

    #[On('deny-email-access-request')]
    public function denyFromNotification(string $requestId): void
    {
        $accessRequest = $this->ownedPendingRequest($requestId);

        if ($accessRequest === null) {
            return;
        }

        resolve(DenyEmailAccessRequestAction::class)->execute($accessRequest, $this->authUser());

        Notification::make()
            ->success()
            ->title(__('filament/pages/email-access-requests.notifications.denied'))
            ->send();

        $this->unmountEmailReaderIfOpen();
    }

    public function approveAccessRequestInline(string $requestId): void
    {
        $this->approveFromNotification($requestId);
        $this->unmountAction();
    }

    public function denyAccessRequestInline(string $requestId): void
    {
        $this->denyFromNotification($requestId);
        $this->unmountAction();
    }

    public function getDefaultActionRecord(Action $action): ?Model
    {
        if ($action->getName() !== 'view') {
            return null;
        }

        $emailId = $action->getArguments()['emailId'] ?? null;

        if (! is_string($emailId)) {
            return null;
        }

        return $this->resolveTeamEmail($emailId, 'view');
    }

    public function getDefaultActionSchemaResolver(Action $action): ?Closure
    {
        return match (true) {
            $action instanceof ViewAction => fn (Schema $schema): Schema => $this->emailReaderInfolist($schema),
            default => null,
        };
    }

    protected function usesInlineAccessActionsInReader(): bool
    {
        return true;
    }

    protected function approveAccessRequestAction(): Action
    {
        return Action::make('approveAccessRequest')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-check-circle')
            ->modalIconColor('success')
            ->modalHeading(__('filament/pages/email-inbox.approve_access_request.modal_heading'))
            ->modalDescription(fn (array $arguments): string => sprintf(
                'Grant %s access to this email?',
                $this->requesterNameForOwnedRequest($arguments['requestId'] ?? null),
            ))
            ->modalSubmitActionLabel('Approve')
            ->color('success')
            ->action(function (array $arguments): void {
                $accessRequest = $this->ownedPendingRequest($arguments['requestId'] ?? null);

                if ($accessRequest === null) {
                    return;
                }

                resolve(ApproveEmailAccessRequestAction::class)->execute($accessRequest, $this->authUser());

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-access-requests.notifications.approved'))
                    ->send();
            });
    }

    protected function denyAccessRequestAction(): Action
    {
        return Action::make('denyAccessRequest')
            ->requiresConfirmation()
            ->modalHeading(__('filament/pages/email-inbox.deny_access_request.modal_heading'))
            ->modalDescription(fn (array $arguments): string => sprintf(
                'Deny %s\'s request for access to this email?',
                $this->requesterNameForOwnedRequest($arguments['requestId'] ?? null),
            ))
            ->modalSubmitActionLabel('Deny')
            ->color('danger')
            ->action(function (array $arguments): void {
                $accessRequest = $this->ownedPendingRequest($arguments['requestId'] ?? null);

                if ($accessRequest === null) {
                    return;
                }

                resolve(DenyEmailAccessRequestAction::class)->execute($accessRequest, $this->authUser());

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-access-requests.notifications.denied'))
                    ->send();
            });
    }

    private function ownedPendingRequest(?string $requestId): ?EmailAccessRequest
    {
        if ($requestId === null) {
            return null;
        }

        return EmailAccessRequest::query()
            ->with(['email', 'owner', 'requester'])
            ->whereKey($requestId)
            ->where('owner_id', $this->authUser()->getKey())
            ->first();
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function unmountEmailReaderIfOpen(): void
    {
        if ($this->getMountedAction()?->getName() === 'view') {
            $this->unmountAction();
        }
    }

    public function render(): View
    {
        return view('email-integration::livewire.email-access-notification-handler');
    }
}
