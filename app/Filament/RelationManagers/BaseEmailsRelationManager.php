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
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\EmailThread;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;
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
                    ->label('Share my emails')
                    ->icon('heroicon-o-share')
                    ->color('gray')
                    ->modalHeading('Share my emails on this record')
                    ->modalDescription('Update visibility and teammate access for all emails you own on this record.')
                    ->modalSubmitActionLabel('Save')
                    ->visible(fn (): bool => $this->getOwnerRecord()
                        ->emails()
                        ->where('user_id', $this->authUser()->getKey())
                        ->exists())
                    ->schema([
                        Select::make('privacy_tier')
                            ->label('Who can see these emails?')
                            ->options(EmailPrivacyTier::class)
                            ->required()
                            ->default(EmailPrivacyTier::METADATA_ONLY->value),

                        Repeater::make('shares')
                            ->label('Share with specific teammates')
                            ->defaultItems(0)
                            ->addActionLabel('Add teammate')
                            ->columns()
                            ->compact()
                            ->schema([
                                Select::make('shared_with')
                                    ->label('Teammate')
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
                                    ->label('Access level')
                                    ->options(EmailPrivacyTier::class)
                                    ->required(),
                            ]),
                    ])
                    ->action(function (array $data, EmailSharingService $sharingService): void {
                        $owner = $this->authUser();
                        $record = $this->getOwnerRecord();
                        $sharingService->setTierForAllOnRecord($record, $owner, $data['privacy_tier']);

                        foreach ($data['shares'] ?? [] as $share) {
                            $sharingService->shareAllOnRecord(
                                $record,
                                $owner,
                                User::query()->findOrFail($share['shared_with']),
                                $share['tier'],
                            );
                        }

                        Notification::make()
                            ->success()
                            ->title('Sharing settings saved for all your emails on this record.')
                            ->send();
                    }),
            ])
            ->columns([
                TextColumn::make('subject')
                    ->label('Subject')
                    ->searchable()
                    ->limit(60)
                    ->getStateUsing(function (Email $record): string {
                        if ($this->authUser()->can('viewSubject', $record)) {
                            return $record->subject ?? '(no subject)';
                        }

                        return '(subject hidden)';
                    }),

                TextColumn::make('from_address')
                    ->label('From')
                    ->getStateUsing(fn (Email $record): string => $record->from->first()?->name
                        ?? $record->from->first()?->email_address
                        ?? '—'),

                TextColumn::make('ai_label')
                    ->label('Label')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Scheduling' => 'info',
                        'Marketing' => 'warning',
                        'Invoice' => 'danger',
                        'Support' => 'success',
                        'Sales' => 'primary',
                        default => 'gray',
                    })
                    ->getStateUsing(fn (Email $record): string => $record->labels->where('source', 'ai')->first()?->label ?? ''),

                TextColumn::make('direction')
                    ->label('Direction')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->getLabel()),

                TextColumn::make('sent_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('privacy_tier')
                    ->label('Visibility')
                    ->badge()
                    ->formatStateUsing(fn (EmailPrivacyTier $state): string => $state->getLabel())
                    ->color(fn (EmailPrivacyTier $state): string => match ($state) {
                        EmailPrivacyTier::PRIVATE => 'gray',
                        EmailPrivacyTier::METADATA_ONLY => 'gray',
                        EmailPrivacyTier::SUBJECT => 'warning',
                        EmailPrivacyTier::FULL => 'success',
                    }),

                TextColumn::make('is_internal')
                    ->label('Internal')
                    ->badge()
                    ->getStateUsing(fn (Email $record): string => ($record->is_internal && $record->user_id === $this->authUser()->getKey()) ? 'Internal' : '')
                    ->color('info'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->modalHeading('Email details')
                    ->modalWidth(Width::SevenExtraLarge)
                    ->slideOver(),

                ActionGroup::make([
                    Action::make('summarizeThread')
                        ->label('Summarize Thread')
                        ->icon('heroicon-o-sparkles')
                        ->color('gray')
                        ->visible(fn (Email $record): bool => filled($record->thread_id) && $this->authUser()->can('viewSubject', $record))
                        ->modalHeading('AI Thread Summary')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        ->modalContent(fn (Email $record): View => $this->buildThreadSummaryView($record)),

                    Action::make('manageSharing')
                        ->label('Sharing')
                        ->icon('heroicon-o-lock-open')
                        ->modalHeading('Sharing settings')
                        ->modalSubmitActionLabel('Save')
                        ->visible(fn (Email $record): bool => $record->user_id === $this->authUser()->getKey())
                        ->schema([
                            Select::make('privacy_tier')
                                ->label('Who can see this email?')
                                ->options(EmailPrivacyTier::class)
                                ->required(),

                            Repeater::make('shares')
                                ->label('Share with specific teammates')
                                ->defaultItems(0)
                                ->addActionLabel('Add teammate')
                                ->columns(2)
                                ->schema([
                                    Select::make('shared_with')
                                        ->label('Teammate')
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
                                        ->label('Access level')
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
                        ->action(function (Email $record, array $data, EmailSharingService $sharingService): void {
                            $sharer = $this->authUser();

                            $sharingService->setEmailTier($record, $data['privacy_tier']);

                            $record->shares()->where('shared_by', $sharer->getKey())->delete();

                            foreach ($data['shares'] ?? [] as $share) {
                                $sharingService->shareEmail(
                                    $record,
                                    $sharer,
                                    User::query()->findOrFail($share['shared_with']),
                                    $share['tier'],
                                );
                            }
                            Notification::make()
                                ->success()
                                ->title('Sharing settings saved.')
                                ->send();
                        }),

                    Action::make('requestAccess')
                        ->label('Request Access')
                        ->icon('heroicon-o-key')
                        ->visible(fn (Email $record): bool => $this->authUser()->cannot('viewBody', $record) && $this->authUser()->can('requestAccess', $record))
                        ->schema([
                            Select::make('tier_requested')
                                ->label('Access level requested')
                                ->options([
                                    EmailPrivacyTier::SUBJECT->value => EmailPrivacyTier::SUBJECT->getLabel(),
                                    EmailPrivacyTier::FULL->value => EmailPrivacyTier::FULL->getLabel(),
                                ])
                                ->required(),
                        ])
                        ->action(function (Email $record, array $data): void {
                            $requester = $this->authUser();

                            $existing = EmailAccessRequest::query()->where('email_id', $record->getKey())
                                ->where('requester_id', $requester->getKey())
                                ->where('status', 'pending')
                                ->exists();

                            if ($existing) {
                                Notification::make()
                                    ->warning()
                                    ->title('You already have a pending request for this email.')
                                    ->send();

                                return;
                            }

                            $request = EmailAccessRequest::query()->create([
                                'email_id' => $record->getKey(),
                                'requester_id' => $requester->getKey(),
                                'owner_id' => $record->user_id,
                                'tier_requested' => $data['tier_requested'],
                                'status' => 'pending',
                            ]);

                            $record->user?->notify(new EmailAccessRequestedNotification($request));

                            Notification::make()
                                ->success()
                                ->title('Access request sent.')
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
