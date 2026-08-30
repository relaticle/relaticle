<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\RelationManagers;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Actions\ConfigureMailboxAction;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailComposeActions;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Services\EmailSharingService;

abstract class BaseEmailsRelationManager extends RelationManager
{
    use HasEmailComposeActions;
    use HasEmailReaderActions;

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
                // participants + shares are read per row by the privacy policy; eager-load
                // them to avoid an N+1 when rendering the subject column.
                ->with(['from', 'labels', 'participants', 'shares'])
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
                                Select::make('tier')
                                    ->label(__('filament/relation-managers/emails.fields.tier.label'))
                                    ->options(EmailPrivacyTier::class)
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->required(),

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
                                    ->multiple()
                                    ->searchable()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->required(),
                            ]),
                    ])
                    ->action(function (array $data, EmailSharingService $sharingService): void {
                        $owner = $this->authUser();
                        $record = $this->getOwnerRecord();
                        $sharingService->setTierForAllOnRecord($record, $owner, $data['privacy_tier']);

                        foreach ($data['shares'] ?? [] as $share) {
                            foreach (Arr::wrap($share['shared_with']) as $sharedWith) {
                                $sharedWithUser = User::query()
                                    ->inTeam($owner->current_team_id)
                                    ->whereKey($sharedWith)
                                    ->first();

                                abort_if($sharedWithUser === null, 403);

                                $sharingService->shareAllOnRecord(
                                    $record,
                                    $owner,
                                    $sharedWithUser,
                                    $share['tier'],
                                );
                            }
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
                $this->viewEmailAction(),

                ActionGroup::make([
                    $this->summarizeThreadAction(),
                    $this->manageSharingAction(),
                    $this->requestAccessAction(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-envelope')
            ->emptyStateHeading(fn (): string => $this->hasActiveConnectedAccount()
                ? __('filament/relation-managers/emails.empty_state.heading')
                : __('filament/pages/email-accounts.not_connected.record.heading'))
            ->emptyStateDescription(fn (): string => $this->hasActiveConnectedAccount()
                ? __('filament/relation-managers/emails.empty_state.description')
                : __('filament/pages/email-accounts.not_connected.record.description'))
            ->emptyStateActions([
                ConfigureMailboxAction::make(),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $this->emailReaderInfolist($schema);
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
