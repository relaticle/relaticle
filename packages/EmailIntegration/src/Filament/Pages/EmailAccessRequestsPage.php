<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\CancelEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final class EmailAccessRequestsPage extends Page
{
    use WithPagination;

    private const int PER_PAGE = 20;

    protected string $view = 'filament.pages.email-access-requests';

    protected static ?string $navigationLabel = null;

    protected static string|\UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return __('filament/pages/email-access-requests.navigation_label');
    }

    public static function getNavigationGroup(): string
    {
        return __('filament/navigation.groups.emails');
    }

    public string $tab = 'incoming';

    #[Url(as: 'status')]
    public ?string $statusFilter = null;

    #[Url(as: 'q')]
    public string $search = '';

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
        return 'primary';
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->statusFilter = null;
        $this->search = '';
        $this->selectedRequestId = null;
        $this->resetPage();
        unset($this->requests, $this->statusCounts);
    }

    public function setStatusFilter(?string $status): void
    {
        $this->statusFilter = $status;
        $this->resetPage();
        unset($this->requests);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        unset($this->requests);
    }

    public function selectRequest(string $id): void
    {
        $this->selectedRequestId = $id;
        unset($this->selectedRequest);
    }

    /**
     * @return LengthAwarePaginator<int, EmailAccessRequest>
     */
    #[Computed]
    public function requests(): LengthAwarePaginator
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

        if ($this->statusFilter !== null && EmailAccessRequestStatus::tryFrom($this->statusFilter) !== null) {
            $query->where('status', $this->statusFilter);
        }

        $term = trim($this->search);
        if ($term !== '') {
            $personColumn = $this->tab === 'incoming' ? 'requester' : 'owner';
            $query->where(function (Builder $q) use ($term, $personColumn): void {
                $q->whereHas($personColumn, fn (Builder $sub): Builder => $sub->where('name', 'ilike', '%'.$term.'%'))
                    ->orWhereHas('email', fn (Builder $sub): Builder => $sub->where('subject', 'ilike', '%'.$term.'%'));
            });
        }

        return $query->latest()->paginate(self::PER_PAGE);
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

    /**
     * Unfiltered counts per status for the active tab — used by filter pills.
     *
     * @return array{pending: int, approved: int, denied: int, total: int}
     */
    #[Computed]
    public function statusCounts(): array
    {
        $user = $this->authUser();

        $query = EmailAccessRequest::query()
            ->whereHas('email', fn (Builder $q): Builder => $q->where('team_id', $user->current_team_id));

        if ($this->tab === 'incoming') {
            $query->where('owner_id', $user->getKey());
        } else {
            $query->where('requester_id', $user->getKey());
        }

        /** @var Collection<string, int> $counts */
        $counts = $query->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => (int) $counts->get(EmailAccessRequestStatus::PENDING->value, 0),
            'approved' => (int) $counts->get(EmailAccessRequestStatus::APPROVED->value, 0),
            'denied' => (int) $counts->get(EmailAccessRequestStatus::DENIED->value, 0),
            'total' => (int) $counts->sum(),
        ];
    }

    protected function openEmailAction(): Action
    {
        return Action::make('openEmail')
            ->label(__('filament/pages/email-access-requests.actions.open_email'))
            ->icon('heroicon-m-arrow-top-right-on-square')
            ->color('gray')
            ->size(Size::ExtraSmall)
            ->outlined()
            ->url(fn (array $arguments): ?string => ($emailId = $arguments['emailId'] ?? null) === null
                ? null
                : EmailInboxPage::getUrl(parameters: ['email' => $emailId], tenant: filament()->getTenant()));
    }

    protected function approveAccessRequestAction(): Action
    {
        return Action::make('approveAccessRequest')
            ->label(__('filament/pages/email-access-requests.actions.approve.label'))
            ->icon('heroicon-m-check')
            ->color('success')
            ->size(Size::ExtraSmall)
            ->requiresConfirmation()
            ->modalHeading(__('filament/pages/email-access-requests.actions.approve.modal_heading'))
            ->modalDescription(fn (array $arguments): string => __('filament/pages/email-access-requests.actions.approve.modal_description', [
                'name' => EmailAccessRequest::query()->whereKey($arguments['requestId'] ?? null)->first()?->requester->name ?? __('filament/pages/email-access-requests.actions.fallback_user'),
            ]))
            ->modalSubmitActionLabel(__('filament/pages/email-access-requests.actions.approve.modal_submit_label'))
            ->action(function (array $arguments): void {
                $accessRequest = EmailAccessRequest::query()
                    ->with(['email', 'owner', 'requester'])
                    ->whereKey($arguments['requestId'] ?? null)
                    ->where('owner_id', $this->authUser()->getKey())
                    ->first();

                if ($accessRequest === null) {
                    return;
                }

                resolve(ApproveEmailAccessRequestAction::class)->execute($accessRequest, $this->authUser());

                $this->selectedRequestId = null;
                unset($this->selectedRequest, $this->requests, $this->pendingIncomingCount);

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-access-requests.notifications.approved'))
                    ->send();
            });
    }

    protected function denyAccessRequestAction(): Action
    {
        return Action::make('denyAccessRequest')
            ->label(__('filament/pages/email-access-requests.actions.deny.label'))
            ->icon('heroicon-m-x-mark')
            ->color('danger')
            ->size(Size::ExtraSmall)
            ->outlined()
            ->requiresConfirmation()
            ->modalHeading(__('filament/pages/email-access-requests.actions.deny.modal_heading'))
            ->modalDescription(fn (array $arguments): string => __('filament/pages/email-access-requests.actions.deny.modal_description', [
                'name' => EmailAccessRequest::query()->whereKey($arguments['requestId'] ?? null)->first()?->requester->name ?? __('filament/pages/email-access-requests.actions.fallback_user'),
            ]))
            ->modalSubmitActionLabel(__('filament/pages/email-access-requests.actions.deny.modal_submit_label'))
            ->action(function (array $arguments): void {
                $accessRequest = EmailAccessRequest::query()
                    ->with(['requester'])
                    ->whereKey($arguments['requestId'] ?? null)
                    ->where('owner_id', $this->authUser()->getKey())
                    ->first();

                if ($accessRequest === null) {
                    return;
                }

                resolve(DenyEmailAccessRequestAction::class)->execute($accessRequest, $this->authUser());

                $this->selectedRequestId = null;
                unset($this->selectedRequest, $this->requests, $this->pendingIncomingCount);

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-access-requests.notifications.denied'))
                    ->send();
            });
    }

    protected function cancelAccessRequestAction(): Action
    {
        return Action::make('cancelAccessRequest')
            ->label(__('filament/pages/email-access-requests.actions.cancel.label'))
            ->icon('heroicon-m-x-mark')
            ->color('danger')
            ->size(Size::ExtraSmall)
            ->outlined()
            ->requiresConfirmation()
            ->modalHeading(__('filament/pages/email-access-requests.actions.cancel.modal_heading'))
            ->modalDescription(fn (array $arguments): string => __('filament/pages/email-access-requests.actions.cancel.modal_description', [
                'name' => EmailAccessRequest::query()->whereKey($arguments['requestId'] ?? null)->first()?->owner->name ?? __('filament/pages/email-access-requests.actions.fallback_user'),
            ]))
            ->modalSubmitActionLabel(__('filament/pages/email-access-requests.actions.cancel.modal_submit_label'))
            ->action(function (array $arguments): void {
                $accessRequest = EmailAccessRequest::query()
                    ->whereKey($arguments['requestId'] ?? null)
                    ->where('requester_id', $this->authUser()->getKey())
                    ->first();

                if ($accessRequest === null) {
                    return;
                }

                resolve(CancelEmailAccessRequestAction::class)->execute($accessRequest);

                $this->selectedRequestId = null;
                unset($this->selectedRequest, $this->requests, $this->statusCounts);

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-access-requests.notifications.cancelled'))
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
