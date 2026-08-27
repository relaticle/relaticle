<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire\Concerns;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\CancelEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

trait InteractsWithEmailAccessRequests
{
    public string $tab = 'incoming';

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->requestsQuery())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('requester.name')
                    ->label(__('filament/pages/email-access-requests.columns.requested_by'))
                    ->searchable()
                    ->visible(fn (): bool => $this->tab === 'incoming'),
                TextColumn::make('owner.name')
                    ->label(__('filament/pages/email-access-requests.columns.sent_to'))
                    ->searchable()
                    ->visible(fn (): bool => $this->tab === 'outgoing'),
                TextColumn::make('email.subject')
                    ->label(__('filament/pages/email-access-requests.columns.email'))
                    ->placeholder(__('filament/pages/email-access-requests.request.no_subject'))
                    ->searchable()
                    ->limit(60),
                TextColumn::make('tier_requested')
                    ->label(__('filament/pages/email-access-requests.columns.access'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => EmailPrivacyTier::from($state)->getLabel()),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')
                    ->label(__('filament/pages/email-access-requests.columns.requested'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('filament/pages/email-access-requests.filters.label'))
                    ->options(EmailAccessRequestStatus::class),
            ])
            ->recordActions([
                $this->openEmailAction(),
                $this->approveAccessRequestAction(),
                $this->denyAccessRequestAction(),
                $this->cancelAccessRequestAction(),
            ]);
    }

    #[Computed]
    public function pendingIncomingCount(): int
    {
        return EmailAccessRequest::query()
            ->where('owner_id', $this->authUser()->getKey())
            ->whereHas('email', fn (Builder $query): Builder => $query->where('team_id', $this->authUser()->current_team_id))
            ->where('status', EmailAccessRequestStatus::PENDING)
            ->count();
    }

    /** @return Builder<EmailAccessRequest> */
    private function requestsQuery(): Builder
    {
        $user = $this->authUser();

        return EmailAccessRequest::query()
            ->with(['email', 'requester', 'owner'])
            ->whereHas('email', fn (Builder $query): Builder => $query->where('team_id', $user->current_team_id))
            ->when(
                $this->tab === 'incoming',
                fn (Builder $query): Builder => $query->where('owner_id', $user->getKey()),
                fn (Builder $query): Builder => $query->where('requester_id', $user->getKey()),
            );
    }

    private function openEmailAction(): Action
    {
        return Action::make('openEmail')
            ->label(__('filament/pages/email-access-requests.actions.open_email'))
            ->hiddenLabel()
            ->tooltip(__('filament/pages/email-access-requests.actions.open_email'))
            ->icon('heroicon-m-arrow-top-right-on-square')
            ->iconButton()
            ->color('gray')
            ->url(fn (EmailAccessRequest $request): ?string => $request->email_id === null
                ? null
                : EmailInboxPage::getUrl(parameters: ['email' => $request->email_id], tenant: filament()->getTenant()));
    }

    private function approveAccessRequestAction(): Action
    {
        return Action::make('approveAccessRequest')
            ->label(__('filament/pages/email-access-requests.actions.approve.label'))
            ->hiddenLabel()
            ->tooltip(__('filament/pages/email-access-requests.actions.approve.label'))
            ->icon('heroicon-m-check')
            ->iconButton()
            ->color('success')
            ->visible(fn (EmailAccessRequest $request): bool => $this->tab === 'incoming' && $request->status === EmailAccessRequestStatus::PENDING)
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-check-circle')
            ->modalIconColor('success')
            ->modalHeading(__('filament/pages/email-access-requests.actions.approve.modal_heading'))
            ->modalDescription(fn (EmailAccessRequest $request): string => __('filament/pages/email-access-requests.actions.approve.modal_description', ['name' => $request->requester->name ?? __('filament/pages/email-access-requests.actions.fallback_user')]))
            ->modalSubmitActionLabel(__('filament/pages/email-access-requests.actions.approve.modal_submit_label'))
            ->action(function (EmailAccessRequest $request): void {
                $accessRequest = EmailAccessRequest::query()->with(['email', 'owner', 'requester'])->whereKey($request->getKey())->where('owner_id', $this->authUser()->getKey())->first();

                if ($accessRequest === null) {
                    return;
                }

                resolve(ApproveEmailAccessRequestAction::class)->execute($accessRequest, $this->authUser());
                unset($this->pendingIncomingCount);
                $this->resetTable();
                $this->dispatch('access-requests:changed');

                Notification::make()->success()->title(__('filament/pages/email-access-requests.notifications.approved'))->send();
            });
    }

    private function denyAccessRequestAction(): Action
    {
        return Action::make('denyAccessRequest')
            ->label(__('filament/pages/email-access-requests.actions.deny.label'))
            ->hiddenLabel()
            ->tooltip(__('filament/pages/email-access-requests.actions.deny.label'))
            ->icon('heroicon-m-x-mark')
            ->iconButton()
            ->color('danger')
            ->visible(fn (EmailAccessRequest $request): bool => $this->tab === 'incoming' && $request->status === EmailAccessRequestStatus::PENDING)
            ->requiresConfirmation()
            ->modalHeading(__('filament/pages/email-access-requests.actions.deny.modal_heading'))
            ->modalDescription(fn (EmailAccessRequest $request): string => __('filament/pages/email-access-requests.actions.deny.modal_description', ['name' => $request->requester->name ?? __('filament/pages/email-access-requests.actions.fallback_user')]))
            ->modalSubmitActionLabel(__('filament/pages/email-access-requests.actions.deny.modal_submit_label'))
            ->action(function (EmailAccessRequest $request): void {
                $accessRequest = EmailAccessRequest::query()->with(['requester'])->whereKey($request->getKey())->where('owner_id', $this->authUser()->getKey())->first();

                if ($accessRequest === null) {
                    return;
                }

                resolve(DenyEmailAccessRequestAction::class)->execute($accessRequest, $this->authUser());
                unset($this->pendingIncomingCount);
                $this->resetTable();
                $this->dispatch('access-requests:changed');

                Notification::make()->success()->title(__('filament/pages/email-access-requests.notifications.denied'))->send();
            });
    }

    private function cancelAccessRequestAction(): Action
    {
        return Action::make('cancelAccessRequest')
            ->label(__('filament/pages/email-access-requests.actions.cancel.label'))
            ->hiddenLabel()
            ->tooltip(__('filament/pages/email-access-requests.actions.cancel.label'))
            ->icon('heroicon-m-x-mark')
            ->iconButton()
            ->color('danger')
            ->visible(fn (EmailAccessRequest $request): bool => $this->tab === 'outgoing' && $request->status === EmailAccessRequestStatus::PENDING)
            ->requiresConfirmation()
            ->modalHeading(__('filament/pages/email-access-requests.actions.cancel.modal_heading'))
            ->modalDescription(fn (EmailAccessRequest $request): string => __('filament/pages/email-access-requests.actions.cancel.modal_description', ['name' => $request->owner->name ?? __('filament/pages/email-access-requests.actions.fallback_user')]))
            ->modalSubmitActionLabel(__('filament/pages/email-access-requests.actions.cancel.modal_submit_label'))
            ->action(function (EmailAccessRequest $request): void {
                $accessRequest = EmailAccessRequest::query()->whereKey($request->getKey())->where('requester_id', $this->authUser()->getKey())->first();

                if ($accessRequest === null) {
                    return;
                }

                resolve(CancelEmailAccessRequestAction::class)->execute($accessRequest, $this->authUser());
                $this->resetTable();
                $this->dispatch('access-requests:changed');

                Notification::make()->success()->title(__('filament/pages/email-access-requests.notifications.cancelled'))->send();
            });
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
