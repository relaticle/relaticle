<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Filament\Concerns\HasEmailComposeActions;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Relaticle\EmailIntegration\Actions\RequestEmailAccessAction;
use Relaticle\EmailIntegration\Actions\UpdateEmailSharingAction;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\EmailThread;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Services\EmailSharingService;
use Relaticle\EmailIntegration\Services\EmailThreadSummaryService;

abstract class BaseEmailsRelationManager extends RelationManager
{
    use HasEmailComposeActions;

    protected static string $relationship = 'emails';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-envelope';

    protected function getCrmRecord(): Model
    {
        return $this->getOwnerRecord();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['from', 'labels'])
                ->withGlobalScope('visible', new VisibleEmailScope($this->authUser())))
            ->recordTitleAttribute('subject')
            ->defaultSort('sent_at', 'desc')
            ->headerActions([
                $this->composeEmailAction(),

                Action::make('shareAllOnRecord')
                    ->label(__('filament/relation-managers/emails.actions.share_all.label'))
                    ->icon('heroicon-o-share')
                    ->color('gray')
                    ->modalHeading(__('filament/relation-managers/emails.actions.share_all.modal_heading'))
                    ->modalDescription('Update visibility and teammate access for all emails you own on this record.')
                    ->modalSubmitActionLabel('Save')
                    ->visible(fn (): bool => $this->getRelationship()
                        ->where('user_id', $this->authUser()->getKey())
                        ->exists())
                    ->schema([
                        Select::make('privacy_tier')
                            ->label(__('filament/relation-managers/emails.fields.privacy_tier_all.label'))
                            ->options(EmailPrivacyTier::class)
                            ->required()
                            ->default(EmailPrivacyTier::METADATA_ONLY->value),

                        Repeater::make('shares')
                            ->label(__('filament/relation-managers/emails.fields.shares.label'))
                            ->defaultItems(0)
                            ->addActionLabel('Add teammate')
                            ->columns()
                            ->compact()
                            ->schema([
                                Select::make('shared_with')
                                    ->label(__('filament/relation-managers/emails.fields.shared_with.label'))
                                    ->options(function (): array {
                                        $user = $this->authUser();

                                        return User::query()
                                            ->where('current_team_id', $user->current_team_id)
                                            ->where('id', '!=', $user->getKey())
                                            ->pluck('name', 'id')
                                            ->all();
                                    })
                                    ->required()
                                    ->distinct(),

                                Select::make('tier')
                                    ->label(__('filament/relation-managers/emails.fields.tier.label'))
                                    ->options(EmailPrivacyTier::class)
                                    ->required(),
                            ]),
                    ])
                    ->action(function (array $data, EmailSharingService $sharingService): void {
                        $owner = $this->authUser();
                        $record = $this->getOwnerRecord();
                        $sharingService->setTierForAllOnRecord($record, $owner, $data['privacy_tier']);

                        foreach ($data['shares'] ?? [] as $share) {
                            $sharedWithUser = User::query()
                                ->inTeam($owner->current_team_id)
                                ->whereKey((string) $share['shared_with'])
                                ->first();

                            abort_if($sharedWithUser === null, 403);

                            $sharingService->shareAllOnRecord(
                                $record,
                                $owner,
                                $sharedWithUser,
                                $share['tier'],
                            );
                        }

                        Notification::make()
                            ->success()
                            ->title(__('filament/relation-managers/emails.notifications.sharing_saved_all.title'))
                            ->send();
                    }),
            ])
            ->columns([
                TextColumn::make('subject')
                    ->label(__('filament/relation-managers/emails.columns.subject.label'))
                    ->searchable()
                    ->limit(60)
                    ->getStateUsing(function (Email $record): string {
                        if ($this->authUser()->can('viewSubject', $record)) {
                            return $record->subject ?? '(no subject)';
                        }

                        return '(subject hidden)';
                    }),

                TextColumn::make('from_address')
                    ->label(__('filament/relation-managers/emails.columns.from_address.label'))
                    ->getStateUsing(fn (Email $record): string => $record->from->first()->name
                        ?? $record->from->first()->email_address
                        ?? '—'),

                TextColumn::make('ai_label')
                    ->label(__('filament/relation-managers/emails.columns.ai_label.label'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Scheduling' => 'info',
                        'Marketing' => 'warning',
                        'Invoice' => 'danger',
                        'Support' => 'success',
                        'Sales' => 'primary',
                        default => 'gray',
                    })
                    ->getStateUsing(fn (Email $record): string => $record->labels->where('source', 'ai')->first()->label ?? ''),

                TextColumn::make('direction')
                    ->label(__('filament/relation-managers/emails.columns.direction.label'))
                    ->badge()
                    ->formatStateUsing(fn (EmailDirection $state): string => $state->getLabel()),

                TextColumn::make('sent_at')
                    ->label(__('filament/relation-managers/emails.columns.sent_at.label'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('privacy_tier')
                    ->label(__('filament/relation-managers/emails.columns.privacy_tier.label'))
                    ->badge()
                    ->formatStateUsing(fn (EmailPrivacyTier $state): string => $state->getLabel())
                    ->color(fn (EmailPrivacyTier $state): string => match ($state) {
                        EmailPrivacyTier::PRIVATE => 'gray',
                        EmailPrivacyTier::METADATA_ONLY => 'gray',
                        EmailPrivacyTier::SUBJECT => 'warning',
                        EmailPrivacyTier::FULL => 'success',
                    }),

                TextColumn::make('is_internal')
                    ->label(__('filament/relation-managers/emails.columns.is_internal.label'))
                    ->badge()
                    ->getStateUsing(fn (Email $record): string => ($record->is_internal && $record->user_id === $this->authUser()->getKey()) ? 'Internal' : '')
                    ->color('info'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->modalHeading(__('filament/relation-managers/emails.actions.view.modal_heading'))
                    ->modalWidth(Width::SevenExtraLarge)
                    ->slideOver(),

                ActionGroup::make([
                    Action::make('summarizeThread')
                        ->label(__('filament/relation-managers/emails.actions.summarize_thread.label'))
                        ->icon('heroicon-o-sparkles')
                        ->color('gray')
                        ->visible(false)
                        ->modalHeading(__('filament/relation-managers/emails.actions.summarize_thread.modal_heading'))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        ->modalContent(fn (Email $record): View => $this->buildThreadSummaryView($record)),

                    Action::make('manageSharing')
                        ->label(__('filament/relation-managers/emails.actions.manage_sharing.label'))
                        ->icon('heroicon-o-lock-open')
                        ->modalHeading(__('filament/relation-managers/emails.actions.manage_sharing.modal_heading'))
                        ->modalSubmitActionLabel('Save')
                        ->visible(fn (Email $record): bool => $record->user_id === $this->authUser()->getKey())
                        ->schema([
                            Select::make('privacy_tier')
                                ->label(__('filament/relation-managers/emails.fields.privacy_tier.label'))
                                ->options(EmailPrivacyTier::class)
                                ->required(),

                            Repeater::make('shares')
                                ->label(__('filament/relation-managers/emails.fields.shares.label'))
                                ->defaultItems(0)
                                ->addActionLabel('Add teammate')
                                ->columns(2)
                                ->schema([
                                    Select::make('shared_with')
                                        ->label(__('filament/relation-managers/emails.fields.shared_with.label'))
                                        ->options(function (): array {
                                            $user = $this->authUser();

                                            return User::query()
                                                ->where('current_team_id', $user->current_team_id)
                                                ->where('id', '!=', $user->getKey())
                                                ->pluck('name', 'id')
                                                ->all();
                                        })
                                        ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                        ->required()
                                        ->distinct(),

                                    Select::make('tier')
                                        ->label(__('filament/relation-managers/emails.fields.tier.label'))
                                        ->options(EmailPrivacyTier::class)
                                        ->required(),
                                ]),
                        ])
                        ->fillForm(fn (Email $record): array => [
                            'privacy_tier' => $record->privacy_tier->value,
                            'shares' => $record->shares()
                                ->get()
                                ->map(fn (EmailShare $share): array => [
                                    'shared_with' => $share->shared_with,
                                    'tier' => $share->tier,
                                ])
                                ->all(),
                        ])
                        ->action(function (Email $record, array $data): void {
                            abort_unless($this->authUser()->can('share', $record), 403);

                            resolve(UpdateEmailSharingAction::class)->execute(
                                $record,
                                $this->authUser(),
                                $data['privacy_tier'] instanceof EmailPrivacyTier
                                    ? $data['privacy_tier']
                                    : EmailPrivacyTier::from($data['privacy_tier']),
                                $data['shares'] ?? [],
                            );

                            Notification::make()
                                ->success()
                                ->title(__('filament/relation-managers/emails.notifications.sharing_saved.title'))
                                ->send();
                        }),

                    Action::make('requestAccess')
                        ->label(__('filament/relation-managers/emails.actions.request_access.label'))
                        ->icon('heroicon-o-key')
                        ->visible(fn (Email $record): bool => $this->authUser()->cannot('viewBody', $record) && $this->authUser()->can('requestAccess', $record))
                        ->schema([
                            Select::make('tier_requested')
                                ->label(__('filament/relation-managers/emails.fields.tier_requested.label'))
                                ->options([
                                    EmailPrivacyTier::SUBJECT->value => EmailPrivacyTier::SUBJECT->getLabel(),
                                    EmailPrivacyTier::FULL->value => EmailPrivacyTier::FULL->getLabel(),
                                ])
                                ->required(),
                        ])
                        ->action(function (Email $record, array $data): void {
                            $request = resolve(RequestEmailAccessAction::class)->execute(
                                $record,
                                $this->authUser(),
                                $data['tier_requested'] instanceof EmailPrivacyTier
                                    ? $data['tier_requested']
                                    : EmailPrivacyTier::from($data['tier_requested']),
                            );

                            if (! $request instanceof EmailAccessRequest) {
                                Notification::make()
                                    ->warning()
                                    ->title(__('filament/relation-managers/emails.notifications.pending_request.title'))
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title(__('filament/relation-managers/emails.notifications.access_request_sent.title'))
                                ->send();
                        }),
                ]),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                ViewEntry::make('email')
                    ->hiddenLabel()
                    ->view('filament.emails.email-view')
                    ->columnSpanFull(),
            ])
            ->columns(1);
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function buildThreadSummaryView(Email $email): View
    {
        $thread = EmailThread::query()->where('thread_id', $email->thread_id)
            ->where('connected_account_id', $email->connected_account_id)
            ->first();

        if ($thread === null) {
            return view('filament.actions.ai-summary', ['summary' => null]);
        }

        $summary = resolve(EmailThreadSummaryService::class)
            ->getSummary($thread, $this->authUser());

        return view('filament.actions.ai-summary', ['summary' => $summary]);
    }
}
