<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire\Concerns;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Support\LikePattern;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\CancelEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Queries\RecordEmailAccessQuery;
use Relaticle\EmailIntegration\Services\EmailSearchService;

trait InteractsWithEmailAccessRequests
{
    public string $tab = 'incoming';

    /** @var array<string, Collection<int, Company|People|Opportunity>> */
    private array $recordAnswersByEmail = [];

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
            ->emptyStateIcon('lucide-key')
            ->emptyStateHeading($this->accessRequestsEmptyHeading(...))
            ->emptyStateDescription(fn (): string => match (true) {
                $this->filteredStatus() instanceof EmailAccessRequestStatus => __('filament/pages/email-access-requests.empty.filtered_description'),
                $this->tab === 'incoming' => __('filament/pages/email-access-requests.empty.incoming_description'),
                default => __('filament/pages/email-access-requests.empty.outgoing_description'),
            })
            ->emptyStateActions([
                Action::make('showAllRequests')
                    ->label(__('filament/pages/email-access-requests.empty.show_all'))
                    ->color('gray')
                    ->visible(fn (): bool => $this->filteredStatus() instanceof EmailAccessRequestStatus)
                    ->action(function (): void {
                        $this->removeTableFilters();
                    }),
            ])
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
                    ->placeholder(__('filament/pages/email-inbox.subject.none'))
                    ->searchable(query: $this->searchVisibleEmailSubject(...))
                    ->getStateUsing($this->visibleEmailSubject(...))
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
                $this->shareRecordMailAction(),
                $this->denyAccessRequestAction(),
                $this->cancelAccessRequestAction(),
            ]);
    }

    #[Computed]
    public function pendingIncomingCount(): int
    {
        $user = $this->authUser();

        return EmailAccessRequest::countPendingIncomingFor($user, $user->currentWorkspace);
    }

    private function accessRequestsEmptyHeading(): string
    {
        $status = $this->filteredStatus();

        if ($status instanceof EmailAccessRequestStatus) {
            return __('filament/pages/email-access-requests.empty.filtered_heading', [
                'status' => mb_strtolower($status->getLabel()),
            ]);
        }

        return $this->tab === 'incoming'
            ? __('filament/pages/email-access-requests.empty.incoming_heading')
            : __('filament/pages/email-access-requests.empty.outgoing_heading');
    }

    private function filteredStatus(): ?EmailAccessRequestStatus
    {
        return EmailAccessRequestStatus::tryFrom((string) ($this->getTableFilterState('status')['value'] ?? ''));
    }

    /** @return Builder<EmailAccessRequest> */
    private function requestsQuery(): Builder
    {
        $user = $this->authUser();

        return EmailAccessRequest::query()
            ->with(['email.shares', 'requester', 'owner'])
            ->where('workspace_id', $user->current_workspace_id)
            ->answerable()
            ->where(fn (Builder $listed): Builder => $listed
                ->open()
                ->orWhere('status', '!=', EmailAccessRequestStatus::PENDING))
            ->when(
                $this->tab === 'incoming',
                fn (Builder $query): Builder => $query->where('owner_id', $user->getKey()),
                fn (Builder $query): Builder => $query->where('requester_id', $user->getKey()),
            );
    }

    private function visibleEmailSubject(EmailAccessRequest $request): ?string
    {
        $email = $request->email;

        if ($request->isForRecord()) {
            $record = $request->record();

            if ($record === null) {
                return null;
            }

            return __('filament/pages/email-access-requests.columns.all_on_record', ['record' => $record->name]);
        }

        if (! $email instanceof Email) {
            return null;
        }

        if (! $this->authUser()->can('viewSubject', $email)) {
            return __('filament/pages/email-inbox.subject.hidden');
        }

        return $email->subject;
    }

    /**
     * @param  Builder<EmailAccessRequest>  $query
     * @return Builder<EmailAccessRequest>
     */
    private function searchVisibleEmailSubject(Builder $query, string $search): Builder
    {
        $viewer = $this->authUser();
        $term = '%'.LikePattern::escape($search).'%';

        return $query->whereHas('email', function (Builder $emailQuery) use ($term, $viewer): void {
            /** @var Builder<Email> $emailQuery */
            $emailQuery->where('subject', 'ilike', $term);
            resolve(EmailSearchService::class)->whereVisibleAtTier($emailQuery, $viewer, EmailPrivacyTier::SUBJECT);
        });
    }

    private function openEmailAction(): Action
    {
        return Action::make('openEmail')
            ->label(__('filament/pages/email-access-requests.actions.open_email'))
            ->hiddenLabel()
            ->tooltip(__('filament/pages/email-access-requests.actions.open_email'))
            ->icon('lucide-external-link')
            ->iconButton()
            ->color('gray')
            ->visible(fn (EmailAccessRequest $request): bool => $request->email_id !== null)
            ->action(function (EmailAccessRequest $request): void {
                $this->dispatch('open-email-from-access-request', emailId: $request->email_id);
            });
    }

    private function approveAccessRequestAction(): Action
    {
        return Action::make('approveAccessRequest')
            ->label(__('filament/pages/email-access-requests.actions.approve.label'))
            ->hiddenLabel()
            ->tooltip(__('filament/pages/email-access-requests.actions.approve.label'))
            ->icon('lucide-check')
            ->iconButton()
            ->color('success')
            ->visible(fn (EmailAccessRequest $request): bool => $this->tab === 'incoming' && $request->isOpen())
            ->requiresConfirmation()
            ->modalIcon('lucide-circle-check')
            ->modalIconColor('success')
            ->modalHeading(__('filament/pages/email-access-requests.actions.approve.modal_heading'))
            ->modalDescription(fn (EmailAccessRequest $request): string => $request->isForRecord()
                ? __('filament/pages/email-access-requests.actions.approve.modal_description_record', ['name' => $request->requester->name ?? __('filament/pages/email-access-requests.actions.fallback_user'), 'record' => $request->record()->name ?? ''])
                : __('filament/pages/email-access-requests.actions.approve.modal_description', ['name' => $request->requester->name ?? __('filament/pages/email-access-requests.actions.fallback_user')]))
            ->modalSubmitActionLabel(__('filament/pages/email-access-requests.actions.approve.modal_submit_label'))
            ->action(function (EmailAccessRequest $request): void {
                $accessRequest = EmailAccessRequest::query()->with(['email', 'owner', 'requester'])->whereKey($request->getKey())->where('owner_id', $this->authUser()->getKey())->first();

                if ($accessRequest === null) {
                    return;
                }

                $this->afterDecision(resolve(ApproveEmailAccessRequestAction::class)->execute($accessRequest, $this->authUser()), 'approved');
            });
    }

    private function shareRecordMailAction(): Action
    {
        return Action::make('shareRecordMail')
            ->label(fn (EmailAccessRequest $request): string => $this->recordAnswers($request)->count() === 1
                ? __('filament/pages/email-access-requests.actions.share_record.label', ['record' => $this->recordAnswers($request)->first()?->name])
                : __('filament/pages/email-access-requests.actions.share_record.label_many'))
            ->icon('lucide-layers')
            ->link()
            ->color('gray')
            ->visible(fn (EmailAccessRequest $request): bool => $this->tab === 'incoming'
                && $request->isOpen()
                && $request->email_id !== null
                && $this->recordAnswers($request)->isNotEmpty())
            ->modalHeading(__('filament/pages/email-access-requests.actions.share_record.modal_heading'))
            ->modalDescription(fn (EmailAccessRequest $request): string => __('filament/pages/email-access-requests.actions.share_record.modal_description', ['name' => $request->requester->name ?? __('filament/pages/email-access-requests.actions.fallback_user')]))
            ->modalSubmitActionLabel(__('filament/pages/email-access-requests.actions.share_record.modal_submit_label'))
            ->schema(fn (EmailAccessRequest $request): array => [
                Radio::make('record')
                    ->hiddenLabel()
                    ->options($this->recordAnswers($request)->mapWithKeys(fn (Company|People|Opportunity $record): array => [$record->getMorphClass().':'.$record->getKey() => $record->name])->all())
                    ->default($this->recordAnswers($request)->map(fn (Company|People|Opportunity $record): string => $record->getMorphClass().':'.$record->getKey())->first())
                    ->required(),
            ])
            ->action(function (array $data, EmailAccessRequest $request): void {
                $accessRequest = EmailAccessRequest::query()->with(['email', 'owner', 'requester'])->whereKey($request->getKey())->where('owner_id', $this->authUser()->getKey())->first();
                $record = $accessRequest === null ? null : $this->recordAnswers($accessRequest)
                    ->first(fn (Company|People|Opportunity $answer): bool => $answer->getMorphClass().':'.$answer->getKey() === ($data['record'] ?? null));

                if ($accessRequest === null || $record === null) {
                    return;
                }

                $this->afterDecision(resolve(ApproveEmailAccessRequestAction::class)->execute($accessRequest, $this->authUser(), $record), 'approved');
            });
    }

    /** @return Collection<int, Company|People|Opportunity> */
    private function recordAnswers(EmailAccessRequest $request): Collection
    {
        $email = $request->email;

        if (! $email instanceof Email || $email->user_id !== $this->authUser()->getKey()) {
            return new Collection;
        }

        return $this->recordAnswersByEmail[$email->getKey()] ??= resolve(RecordEmailAccessQuery::class)->recordsOf($email);
    }

    private function denyAccessRequestAction(): Action
    {
        return Action::make('denyAccessRequest')
            ->label(__('filament/pages/email-access-requests.actions.deny.label'))
            ->hiddenLabel()
            ->tooltip(__('filament/pages/email-access-requests.actions.deny.label'))
            ->icon('lucide-x')
            ->iconButton()
            ->color('danger')
            ->visible(fn (EmailAccessRequest $request): bool => $this->tab === 'incoming' && $request->isOpen())
            ->requiresConfirmation()
            ->modalHeading(__('filament/pages/email-access-requests.actions.deny.modal_heading'))
            ->modalDescription(fn (EmailAccessRequest $request): string => __('filament/pages/email-access-requests.actions.deny.modal_description', ['name' => $request->requester->name ?? __('filament/pages/email-access-requests.actions.fallback_user')]))
            ->modalSubmitActionLabel(__('filament/pages/email-access-requests.actions.deny.modal_submit_label'))
            ->action(function (EmailAccessRequest $request): void {
                $accessRequest = EmailAccessRequest::query()->with(['requester'])->whereKey($request->getKey())->where('owner_id', $this->authUser()->getKey())->first();

                if ($accessRequest === null) {
                    return;
                }

                $this->afterDecision(resolve(DenyEmailAccessRequestAction::class)->execute($accessRequest, $this->authUser()), 'denied');
            });
    }

    private function cancelAccessRequestAction(): Action
    {
        return Action::make('cancelAccessRequest')
            ->label(__('filament/pages/email-access-requests.actions.cancel.label'))
            ->hiddenLabel()
            ->tooltip(__('filament/pages/email-access-requests.actions.cancel.label'))
            ->icon('lucide-x')
            ->iconButton()
            ->color('danger')
            ->visible(fn (EmailAccessRequest $request): bool => $this->tab === 'outgoing' && $request->isOpen())
            ->requiresConfirmation()
            ->modalHeading(__('filament/pages/email-access-requests.actions.cancel.modal_heading'))
            ->modalDescription(fn (EmailAccessRequest $request): string => __('filament/pages/email-access-requests.actions.cancel.modal_description', ['name' => $request->owner->name ?? __('filament/pages/email-access-requests.actions.fallback_user')]))
            ->modalSubmitActionLabel(__('filament/pages/email-access-requests.actions.cancel.modal_submit_label'))
            ->action(function (EmailAccessRequest $request): void {
                $accessRequest = EmailAccessRequest::query()->whereKey($request->getKey())->where('requester_id', $this->authUser()->getKey())->first();

                if ($accessRequest === null) {
                    return;
                }

                $this->afterDecision(resolve(CancelEmailAccessRequestAction::class)->execute($accessRequest, $this->authUser()), 'cancelled');
            });
    }

    private function afterDecision(bool $decided, string $outcome): void
    {
        unset($this->pendingIncomingCount);
        $this->resetTable();
        $this->dispatch('access-requests:changed');
        $this->dispatch('databaseNotificationsSent');

        $this->notifyDecision($decided, $outcome);
    }

    private function notifyDecision(bool $decided, string $outcome): void
    {
        $notification = Notification::make();

        if ($decided) {
            $notification->success()->title(__("filament/pages/email-access-requests.notifications.{$outcome}"));
        } else {
            $notification->warning()->title(__('filament/pages/email-access-requests.notifications.no_longer_pending'));
        }

        $notification->send();
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
