<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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
    protected static string $relationship = 'emails';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-envelope';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['from', 'labels'])
                ->withGlobalScope('visible', new VisibleEmailScope($this->authUser())))
            ->recordTitleAttribute('subject')
            ->defaultSort('sent_at', 'desc')
            ->headerActions([
                Action::make('shareAllOnRecord')
                    ->label('Share my emails')
                    ->icon('heroicon-o-share')
                    ->color('gray')
                    ->modalHeading('Share my emails on this record')
                    ->modalDescription('Update visibility and teammate access for all emails you own on this record.')
                    ->modalSubmitActionLabel('Save')
                    ->visible(function (): bool {
                        return $this->getOwnerRecord()
                            ->emails()
                            ->where('user_id', $this->authUser()->getKey())
                            ->exists();
                    })
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

                                        return User::where('current_team_id', $user->current_team_id)
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
                        $tier = EmailPrivacyTier::from($data['privacy_tier']);

                        $sharingService->setTierForAllOnRecord($record, $owner, $tier);

                        foreach ($data['shares'] ?? [] as $share) {
                            $sharingService->shareAllOnRecord(
                                $record,
                                $owner,
                                User::findOrFail($share['shared_with']),
                                EmailPrivacyTier::from($share['tier']),
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
                    ->formatStateUsing(fn (EmailPrivacyTier $state) => $state->getLabel())
                    ->color(fn (EmailPrivacyTier $state) => match ($state) {
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
                ViewAction::make()->slideOver(),

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

                                            return User::where('current_team_id', $user->current_team_id)
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

                            $sharingService->setEmailTier($record, EmailPrivacyTier::from($data['privacy_tier']));

                            $record->shares()->where('shared_by', $sharer->getKey())->delete();

                            foreach ($data['shares'] ?? [] as $share) {
                                $sharingService->shareEmail(
                                    $record,
                                    $sharer,
                                    User::findOrFail($share['shared_with']),
                                    EmailPrivacyTier::from($share['tier']),
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

                            $existing = EmailAccessRequest::where('email_id', $record->getKey())
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

                            $request = EmailAccessRequest::create([
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
        return $schema->schema([

            // ── Internal email notice (owner only) ─────────────────────────
            Section::make('Internal Email')
                ->schema([
                    TextEntry::make('is_internal')
                        ->label('')
                        ->formatStateUsing(fn (): string => 'This email is between workspace members only and is automatically hidden from teammates\' views.')
                        ->columnSpanFull(),
                ])
                ->visible(fn (Email $record): bool => $record->is_internal && $record->user_id === $this->authUser()->getKey()),

            // ── Metadata (always visible) ───────────────────────────────────
            Section::make('Email Details')
                ->schema([
                    TextEntry::make('sent_at')
                        ->label('Date')
                        ->dateTime(),

                    TextEntry::make('direction')
                        ->label('Direction')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state->getLabel()),

                    TextEntry::make('privacy_tier')
                        ->label('Visibility')
                        ->badge()
                        ->formatStateUsing(fn (EmailPrivacyTier $state) => $state->getLabel()),

                    TextEntry::make('has_attachments')
                        ->label('Has Attachments')
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                ])
                ->columns(2),

            // ── Participants (always visible) ───────────────────────────────
            Section::make('Participants')
                ->schema([
                    RepeatableEntry::make('participants')
                        ->label('')
                        ->schema([
                            TextEntry::make('role')
                                ->label('Role')
                                ->badge()
                                ->formatStateUsing(fn ($state) => strtoupper($state->value)),
                            TextEntry::make('name')
                                ->label('Name')
                                ->default('—'),
                            TextEntry::make('email_address')
                                ->label('Email'),
                        ])
                        ->columns(3),
                ]),

            // ── Labels ──────────────────────────────────────────────────────
            Section::make('Labels')
                ->schema([
                    TextEntry::make('labels_display')
                        ->label('')
                        ->badge()
                        ->getStateUsing(fn (Email $record): array => $record->labels->pluck('label')->all())
                        ->color(fn (string $state): string => match ($state) {
                            'Scheduling' => 'info',
                            'Marketing' => 'warning',
                            'Invoice' => 'danger',
                            'Support' => 'success',
                            'Sales' => 'primary',
                            default => 'gray',
                        })
                        ->separator(','),
                ])
                ->visible(fn (Email $record): bool => $record->labels->isNotEmpty()),

            // ── Subject (visible at SUBJECT tier or above) ──────────────────
            Section::make('Subject')
                ->schema([
                    TextEntry::make('subject')
                        ->label('')
                        ->size(TextSize::Large),
                ])
                ->visible(fn (Email $record): bool => $this->authUser()->can('viewSubject', $record)),

            // ── Attachments (visible at FULL tier only) ──────────────────────
            Section::make('Attachments')
                ->schema([
                    RepeatableEntry::make('attachments')
                        ->label('')
                        ->schema([
                            TextEntry::make('filename')
                                ->label('File'),
                            TextEntry::make('mime_type')
                                ->label('Type'),
                            TextEntry::make('size')
                                ->label('Size')
                                ->formatStateUsing(fn (int $state): string => number_format($state / 1024, 1).' KB'),
                        ])
                        ->columns(3),
                ])
                ->visible(fn (Email $record): bool => $record->has_attachments && $this->authUser()->can('viewBody', $record)),

            // ── Body (visible at FULL tier only) ─────────────────────────────
            Section::make('Message Body')
                ->schema([
                    TextEntry::make('body.body_html')
                        ->label('')
                        ->html()
                        ->getStateUsing(fn (Email $record): string => $record->body?->body_html
                            ?? nl2br(e($record->body?->body_text ?? '')))
                        ->columnSpanFull(),
                ])
                ->visible(fn (Email $record): bool => $this->authUser()->can('viewBody', $record)),

            // ── Privacy gate notice ─────────────────────────────────────────
            Section::make('Access Restricted')
                ->schema([
                    TextEntry::make('privacy_tier')
                        ->label('')
                        ->formatStateUsing(fn (EmailPrivacyTier $state): string => match ($state) {
                            EmailPrivacyTier::METADATA_ONLY => 'You can see participant and date information for this email. The subject and body are hidden. Request access to see more.',
                            EmailPrivacyTier::SUBJECT => 'You can see the subject line. The full email body is hidden. Request access to see more.',
                            default => 'This email is private.',
                        })
                        ->columnSpanFull(),
                ])
                ->visible(fn (Email $record): bool => ! $this->authUser()->can('viewBody', $record)),

        ])->columns(1);
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function buildThreadSummaryView(Email $email): View
    {
        $thread = EmailThread::where('thread_id', $email->thread_id)
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
