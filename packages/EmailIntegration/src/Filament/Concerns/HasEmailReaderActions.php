<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Actions\RequestEmailAccessAction;
use Relaticle\EmailIntegration\Actions\UpdateEmailSharingAction;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\EmailThread;
use Relaticle\EmailIntegration\Services\EmailThreadSummaryService;

/**
 * Sharing, summarize, and request-access actions for `x-emails.email-view`,
 * used by the inbox, CRM record email pages, and the emails relation manager.
 */
trait HasEmailReaderActions
{
    protected function viewEmailAction(): ViewAction
    {
        return ViewAction::make()
            ->modalHeading(__('filament/relation-managers/emails.actions.view.modal_heading'))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('filament/emails/composer.actions.close'))
            ->extraModalWindowAttributes([
                'class' => 'fi-email-reader-modal',
            ]);
    }

    public function emailReaderInfolist(Schema $schema): Schema
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

    protected function manageSharingAction(): Action
    {
        return Action::make('manageSharing')
            ->label(__('filament/pages/record-emails.actions.manage_sharing.label'))
            ->icon('ri-share-line')
            ->color('gray')
            ->iconButton()
            ->extraAttributes(['class' => 'fi-email-reader-action'])
            ->tooltip(__('filament/pages/record-emails.actions.manage_sharing.label'))
            ->modalHeading(__('filament/pages/record-emails.actions.manage_sharing.modal_heading'))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel(__('filament/pages/record-emails.actions.manage_sharing.submit'))
            ->visible(function (mixed $record = null): bool {
                if (! $record instanceof Email) {
                    return true;
                }

                return $record->user_id === $this->readerUser()->getKey();
            })
            ->schema([
                Grid::make(['default' => 1, 'md' => 12])
                    ->schema([
                        Section::make(__('filament/pages/record-emails.fields.privacy_tier.label'))
                            ->icon('heroicon-o-globe-alt')
                            ->compact()
                            ->columnSpan(['default' => 1, 'md' => 5])
                            ->schema([
                                Radio::make('privacy_tier')
                                    ->hiddenLabel()
                                    ->options(EmailPrivacyTier::class)
                                    ->view('email-integration::forms.sharing-tier-cards')
                                    ->viewData(['ariaLabel' => __('filament/pages/record-emails.fields.privacy_tier.label')])
                                    ->required(),
                            ]),

                        Section::make(__('filament/pages/record-emails.fields.shares.label'))
                            ->description(__('filament/pages/email-inbox.sharing.fields.shares.description'))
                            ->icon('heroicon-o-user-group')
                            ->compact()
                            ->columnSpan(['default' => 1, 'md' => 7])
                            ->schema([
                                Repeater::make('shares')
                                    ->hiddenLabel()
                                    ->defaultItems(0)
                                    ->reorderable(false)
                                    ->addActionLabel(__('filament/pages/email-inbox.sharing.fields.shares.add_action_label'))
                                    ->itemLabel(fn (array $state): string => $this->shareRowLabel($state))
                                    ->columns(2)
                                    ->schema([
                                        Select::make('tier')
                                            ->label(__('filament/pages/record-emails.fields.tier.label'))
                                            ->hiddenLabel()
                                            ->options(EmailPrivacyTier::class)
                                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                            ->required(),

                                        Select::make('shared_with')
                                            ->label(__('filament/pages/record-emails.fields.shared_with.label'))
                                            ->hiddenLabel()
                                            ->placeholder(__('filament/pages/email-inbox.sharing.fields.shared_with.placeholder'))
                                            ->options(fn (): array => $this->teammateOptions())
                                            ->multiple()
                                            ->searchable()
                                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                            ->required(),
                                    ]),
                            ]),
                    ]),
            ])
            ->fillForm(function (array $arguments, mixed $record = null): array {
                $email = $this->emailForReaderAction($record instanceof Email ? $record : null, $arguments, 'share');

                if (! $email instanceof Email) {
                    return [];
                }

                return [
                    'privacy_tier' => $email->privacy_tier->value,
                    'shares' => $this->shareFormRows($email),
                ];
            })
            ->action(function (array $data, array $arguments, mixed $record = null): void {
                $email = $this->emailForReaderAction($record instanceof Email ? $record : null, $arguments, 'share');

                abort_if(! $email instanceof Email, 403);

                resolve(UpdateEmailSharingAction::class)->execute(
                    $email,
                    $this->readerUser(),
                    $data['privacy_tier'] instanceof EmailPrivacyTier
                        ? $data['privacy_tier']
                        : EmailPrivacyTier::from($data['privacy_tier']),
                    $data['shares'] ?? [],
                );

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/record-emails.notifications.sharing_saved.title'))
                    ->send();
            });
    }

    protected function summarizeThreadAction(): Action
    {
        return Action::make('summarizeThread')
            ->label(__('filament/pages/record-emails.actions.summarize_thread.label'))
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->iconButton()
            ->extraAttributes(['class' => 'fi-email-reader-action'])
            ->tooltip(__('filament/pages/record-emails.actions.summarize_thread.label'))
            ->visible(function (mixed $record = null): bool {
                if (! $record instanceof Email) {
                    return true;
                }

                return $record->user_id === $this->readerUser()->getKey()
                    || $this->readerUser()->can('viewBody', $record);
            })
            ->modalHeading(__('filament/pages/record-emails.actions.summarize_thread.modal_heading'))
            ->modalIcon('heroicon-o-sparkles')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('filament/emails/composer.actions.close'))
            ->modalContent(function (array $arguments, mixed $record = null): View {
                $email = $this->emailForReaderAction($record instanceof Email ? $record : null, $arguments, 'viewBody');

                if (! $email instanceof Email) {
                    return view('filament.actions.ai-summary', ['summary' => null]);
                }

                return $this->buildThreadSummaryView($email);
            });
    }

    protected function requestAccessAction(): Action
    {
        return Action::make('requestAccess')
            ->label(__('filament/pages/record-emails.actions.request_access.label'))
            ->icon('heroicon-o-key')
            ->color('gray')
            ->iconButton()
            ->extraAttributes(['class' => 'fi-email-reader-action'])
            ->tooltip(__('filament/pages/record-emails.actions.request_access.label'))
            ->visible(function (mixed $record = null): bool {
                if (! $record instanceof Email) {
                    return true;
                }

                return $this->readerUser()->cannot('viewBody', $record)
                    && $this->readerUser()->can('requestAccess', $record);
            })
            ->schema([
                Select::make('tier_requested')
                    ->label(__('filament/pages/record-emails.fields.tier_requested.label'))
                    ->options([
                        EmailPrivacyTier::SUBJECT->value => EmailPrivacyTier::SUBJECT->getLabel(),
                        EmailPrivacyTier::FULL->value => EmailPrivacyTier::FULL->getLabel(),
                    ])
                    ->required(),
            ])
            ->action(function (array $data, array $arguments, mixed $record = null): void {
                $email = $this->emailForReaderAction($record instanceof Email ? $record : null, $arguments, 'requestAccess');

                abort_if(! $email instanceof Email, 403);

                $request = resolve(RequestEmailAccessAction::class)->execute(
                    $email,
                    $this->readerUser(),
                    $data['tier_requested'] instanceof EmailPrivacyTier
                        ? $data['tier_requested']
                        : EmailPrivacyTier::from($data['tier_requested']),
                );

                if (! $request instanceof EmailAccessRequest) {
                    Notification::make()
                        ->warning()
                        ->title(__('filament/pages/record-emails.notifications.pending_request.title'))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/record-emails.notifications.access_request_sent.title'))
                    ->send();
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function emailForReaderAction(?Email $record, array $arguments, string $ability): ?Email
    {
        $emailId = $arguments['emailId'] ?? null;

        if (is_string($emailId) || is_int($emailId)) {
            return $this->resolveTeamEmail((string) $emailId, $ability);
        }

        if (! $record instanceof Email) {
            return null;
        }

        if (! $this->readerUser()->can($ability, $record)) {
            return null;
        }

        return $record;
    }

    protected function resolveTeamEmail(?string $emailId, string $ability): ?Email
    {
        if ($emailId === null) {
            return null;
        }

        $user = $this->readerUser();

        $email = Email::query()
            ->forTeam($user->current_team_id)
            ->whereKey($emailId)
            ->first();

        if ($email === null) {
            return null;
        }

        if (! $user->can($ability, $email)) {
            return null;
        }

        return $email;
    }

    /**
     * @return array<int, array{tier: string, shared_with: array<int, int|string>}>
     */
    private function shareFormRows(Email $email): array
    {
        return $email->shares()
            ->get()
            ->groupBy(fn (EmailShare $share): string => $this->tierValue($share->tier))
            ->map(fn (Collection $shares, string $tier): array => [
                'tier' => $tier,
                'shared_with' => $shares->pluck('shared_with')->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{tier?: mixed}  $state
     */
    private function shareRowLabel(array $state): string
    {
        $tier = $state['tier'] ?? null;

        if ($tier instanceof EmailPrivacyTier) {
            return $tier->getLabel();
        }

        if (is_string($tier) || is_int($tier)) {
            return EmailPrivacyTier::tryFrom((string) $tier)?->getLabel()
                ?? __('filament/pages/email-inbox.sharing.fields.shares.new_item');
        }

        return __('filament/pages/email-inbox.sharing.fields.shares.new_item');
    }

    private function tierValue(mixed $tier): string
    {
        if ($tier instanceof EmailPrivacyTier) {
            return $tier->value;
        }

        if (is_string($tier) || is_int($tier)) {
            return (string) $tier;
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function teammateOptions(): array
    {
        $user = $this->readerUser();

        return User::query()
            ->inTeam($user->current_team_id)
            ->whereKeyNot($user->getKey())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function buildThreadSummaryView(Email $email): View
    {
        $thread = EmailThread::query()
            ->where('thread_id', $email->thread_id)
            ->where('connected_account_id', $email->connected_account_id)
            ->first();

        if ($thread === null) {
            return view('filament.actions.ai-summary', ['summary' => null]);
        }

        $summary = resolve(EmailThreadSummaryService::class)
            ->getSummary($thread, $this->readerUser());

        return view('filament.actions.ai-summary', ['summary' => $summary]);
    }

    protected function requesterNameForOwnedRequest(?string $requestId): string
    {
        if ($requestId === null) {
            return 'this user';
        }

        return EmailAccessRequest::query()
            ->whereKey($requestId)
            ->where('owner_id', $this->readerUser()->getKey())
            ->first()?->requester->name ?? 'this user';
    }

    private function readerUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
