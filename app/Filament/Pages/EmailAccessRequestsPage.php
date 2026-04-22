<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final class EmailAccessRequestsPage extends Page
{
    protected string $view = 'filament.pages.email-access-requests';

    protected static ?string $navigationLabel = 'Access Requests';

    protected static string|\UnitEnum|null $navigationGroup = 'Emails';

    protected static ?int $navigationSort = 2;

    public string $tab = 'incoming';

    #[Url(as: 'request')]
    public ?string $selectedRequestId = null;

    public static function getNavigationBadge(): ?string
    {
        /** @var User|null $user */
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $count = EmailAccessRequest::query()
            ->where('owner_id', $user->getKey())
            ->where('status', EmailAccessRequestStatus::PENDING)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->selectedRequestId = null;
        unset($this->requests);
    }

    public function selectRequest(string $id): void
    {
        $this->selectedRequestId = $id;
        unset($this->selectedRequest);
    }

    /**
     * @return Collection<int, EmailAccessRequest>
     */
    #[Computed]
    public function requests(): Collection
    {
        $user = $this->authUser();

        $query = EmailAccessRequest::query()
            ->with(['email.from', 'requester', 'owner'])
            ->whereHas('email', fn (Builder $q): Builder => $q->where('team_id', $user->current_team_id));

        if ($this->tab === 'incoming') {
            $query->where('owner_id', $user->getKey());
        } else {
            $query->where('requester_id', $user->getKey());
        }

        return $query->latest()->get();
    }

    #[Computed]
    public function selectedRequest(): ?EmailAccessRequest
    {
        if ($this->selectedRequestId === null) {
            return null;
        }

        /** @var EmailAccessRequest|null */
        return EmailAccessRequest::query()
            ->with(['email.from', 'email.body', 'requester', 'owner'])
            ->whereKey($this->selectedRequestId)
            ->first();
    }

    #[Computed]
    public function pendingIncomingCount(): int
    {
        return EmailAccessRequest::query()
            ->where('owner_id', $this->authUser()->getKey())
            ->whereHas('email', fn (Builder $q): Builder => $q->where('team_id', $this->authUser()->current_team_id))
            ->where('status', EmailAccessRequestStatus::PENDING)
            ->count();
    }

    protected function approveAccessRequestAction(): Action
    {
        return Action::make('approveAccessRequest')
            ->requiresConfirmation()
            ->modalHeading('Approve access request')
            ->modalDescription(fn (array $arguments): string => sprintf(
                'Grant %s access to this email?',
                EmailAccessRequest::query()->whereKey($arguments['requestId'] ?? null)->first()?->requester->name ?? 'this user',
            ))
            ->modalSubmitActionLabel('Approve')
            ->color('success')
            ->action(function (array $arguments): void {
                $accessRequest = EmailAccessRequest::query()
                    ->with(['email', 'owner', 'requester'])
                    ->whereKey($arguments['requestId'] ?? null)
                    ->where('owner_id', $this->authUser()->getKey())
                    ->first();

                if ($accessRequest === null) {
                    return;
                }

                resolve(ApproveEmailAccessRequestAction::class)->execute($accessRequest);

                $this->selectedRequestId = null;
                unset($this->selectedRequest, $this->requests, $this->pendingIncomingCount);

                Notification::make()
                    ->success()
                    ->title('Access request approved.')
                    ->send();
            });
    }

    protected function denyAccessRequestAction(): Action
    {
        return Action::make('denyAccessRequest')
            ->requiresConfirmation()
            ->modalHeading('Deny access request')
            ->modalDescription(fn (array $arguments): string => sprintf(
                'Deny %s\'s access request?',
                EmailAccessRequest::query()->whereKey($arguments['requestId'] ?? null)->first()?->requester->name ?? 'this user',
            ))
            ->modalSubmitActionLabel('Deny')
            ->color('danger')
            ->action(function (array $arguments): void {
                $accessRequest = EmailAccessRequest::query()
                    ->with(['requester'])
                    ->whereKey($arguments['requestId'] ?? null)
                    ->where('owner_id', $this->authUser()->getKey())
                    ->first();

                if ($accessRequest === null) {
                    return;
                }

                resolve(DenyEmailAccessRequestAction::class)->execute($accessRequest);

                $this->selectedRequestId = null;
                unset($this->selectedRequest, $this->requests, $this->pendingIncomingCount);

                Notification::make()
                    ->success()
                    ->title('Access request denied.')
                    ->send();
            });
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
