<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use App\Enums\CrmEntity;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Exceptions\AiException;
use Livewire\Attributes\On;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\LinkEmailToRecord;
use Relaticle\EmailIntegration\Actions\MarkEmailAsReadAction;
use Relaticle\EmailIntegration\Actions\RequestEmailAccessAction;
use Relaticle\EmailIntegration\Actions\RestoreEmailRecordLinks;
use Relaticle\EmailIntegration\Actions\UnlinkEmailFromRecord;
use Relaticle\EmailIntegration\Actions\UpdateEmailSharingAction;
use Relaticle\EmailIntegration\Data\ConversationFilters;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\EmailThread;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Queries\EmailLinkTargetsQuery;
use Relaticle\EmailIntegration\Services\EmailThreadSummaryService;

/**
 * Sharing, summarize, and request-access actions for `x-email-integration::email-view`,
 * used by the emails relation manager and the access notification handler.
 *
 * @property ?string $selectedEmailId
 * @property-read Email|null $selectedEmail
 */
trait HasEmailReaderActions
{
    abstract public function refreshConversations(): void;

    abstract public function deselectEmail(): void;

    /** @var EloquentCollection<int, Email>|null */
    private ?EloquentCollection $copyConversation = null;

    /**
     * Open the reader on the host's selected copy. A copy below the body tier opens only
     * where the host's selection allows it (the conversations drawer), and is never marked read.
     */
    protected function openEmailReader(string $id): bool
    {
        $this->selectedEmailId = $id;
        unset($this->selectedEmail);
        $email = $this->selectedEmail;

        if (! $email instanceof Email) {
            $this->selectedEmailId = null;
            unset($this->selectedEmail);

            return false;
        }

        $this->dispatch('composer:dismiss-inline');
        $this->dispatch('composer:resume-draft', emailId: $id);

        if ($this->readerUser()->can('viewBody', $email)) {
            resolve(MarkEmailAsReadAction::class)->execute([$id], $this->readerUser());
        }

        return true;
    }

    /**
     * The copy a conversation list may open: one of the viewer's preferred visible copies, at any tier.
     */
    protected function conversationCopy(string $emailId): ?Email
    {
        $user = $this->readerUser();
        $conversationKey = Email::query()
            ->forWorkspace($user->current_workspace_id)
            ->whereKey($emailId)
            ->value('conversation_key');

        if (! is_string($conversationKey)) {
            return null;
        }

        $this->copyConversation = resolve(ConversationsQuery::class)->messages($user, $conversationKey);
        $email = $this->copyConversation->find($emailId);

        return $email instanceof Email ? $email->loadMissing(['labels', 'from']) : null;
    }

    // The conversation this request already loaded the copy from, so the thread need not query it again.
    /** @return EloquentCollection<int, Email>|null */
    protected function loadedConversationOf(Email $copy): ?EloquentCollection
    {
        return $this->copyConversation?->contains(fn (Email $message): bool => $message === $copy) === true
            ? $this->copyConversation
            : null;
    }

    /**
     * @return Collection<int, EmailAccessRequest>
     */
    protected function pendingAccessRequestsFor(Email $email): Collection
    {
        if ($email->user_id !== $this->readerUser()->getKey()) {
            return collect();
        }

        return EmailAccessRequest::query()
            ->with('requester')
            ->where('email_id', $email->getKey())
            ->open()
            ->get();
    }

    protected function manageSharingAction(): Action
    {
        return Action::make('manageSharing')
            ->label(__('filament/pages/record-emails.actions.manage_sharing.label'))
            ->icon('lucide-share-2')
            ->color('gray')
            ->iconButton()
            ->extraAttributes(['class' => 'fi-email-reader-action'])
            ->tooltip(__('filament/pages/record-emails.actions.manage_sharing.label'))
            ->modalHeading(__('filament/pages/record-emails.actions.manage_sharing.modal_heading'))
            ->modalWidth(Width::ExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel(__('filament/pages/record-emails.actions.manage_sharing.submit'))
            ->visible(function (mixed $record = null): bool {
                if (! $record instanceof Email) {
                    return true;
                }

                return $record->user_id === $this->readerUser()->getKey();
            })
            ->schema([
                Section::make(__('filament/pages/record-emails.fields.privacy_tier.label'))
                    ->icon('lucide-globe')
                    ->compact()
                    ->columnSpanFull()
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
                    ->icon('lucide-users')
                    ->compact()
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('shares')
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->addActionLabel(__('filament/pages/email-inbox.sharing.fields.shares.add_action_label'))
                            ->itemLabel(fn (array $state): string => $this->shareRowLabel($state))
                            ->schema([
                                Fieldset::make()
                                    ->hiddenLabel()
                                    ->columns(3)
                                    ->schema([
                                        Select::make('tier')
                                            ->label(__('filament/pages/record-emails.fields.tier.label'))
                                            ->options(EmailPrivacyTier::class)
                                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                            ->required()
                                            ->columnSpan(1),
                                        Select::make('shared_with')
                                            ->label(__('filament/pages/record-emails.fields.shared_with.label'))
                                            ->placeholder(__('filament/pages/email-inbox.sharing.fields.shared_with.placeholder'))
                                            ->options(fn (): array => $this->teammateOptions())
                                            ->multiple()
                                            ->searchable()
                                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                            ->required()
                                            ->columnSpan(2),
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
            ->icon('lucide-sparkles')
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
            ->modalIcon('lucide-sparkles')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('filament/emails/composer.actions.close'))
            ->modalContent(function (array $arguments, mixed $record = null): View {
                $email = $this->emailForReaderAction($record instanceof Email ? $record : null, $arguments, 'viewBody');

                if (! $email instanceof Email) {
                    return view('email-integration::filament.actions.ai-summary', ['summary' => null, 'failed' => false]);
                }

                return $this->buildThreadSummaryView($email);
            });
    }

    protected function requestAccessAction(): Action
    {
        return Action::make('requestAccess')
            ->label(__('filament/pages/record-emails.actions.request_access.label'))
            ->icon('lucide-key')
            ->color('gray')
            ->iconButton()
            ->extraAttributes(['class' => 'fi-email-reader-action'])
            ->tooltip(__('filament/pages/record-emails.actions.request_access.label'))
            ->modalHeading(__('filament/pages/record-emails.actions.request_access.modal_heading'))
            ->modalWidth(Width::Large)
            ->visible(function (mixed $record = null, array $arguments = []): bool {
                $email = $record instanceof Email
                    ? $record
                    : $this->emailForReaderAction(null, $arguments, 'requestAccess');

                if (! $email instanceof Email) {
                    return ! array_key_exists('emailId', $arguments);
                }

                return $this->canOpenRequestAccess($email);
            })
            ->schema([
                Radio::make('tier_requested')
                    ->hiddenLabel()
                    ->options([
                        EmailPrivacyTier::SUBJECT->value => EmailPrivacyTier::SUBJECT->getLabel(),
                        EmailPrivacyTier::FULL->value => EmailPrivacyTier::FULL->getLabel(),
                    ])
                    ->view('email-integration::forms.request-access-tier-cards')
                    ->viewData([
                        'ariaLabel' => __('filament/pages/record-emails.fields.tier_requested.label'),
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

    protected function linkRecordAction(): Action
    {
        return Action::make('linkRecord')
            ->label(__('filament/pages/email-inbox.drawer.links.add'))
            ->icon('ri-link')
            ->modalHeading(__('filament/pages/email-inbox.drawer.links.picker.heading'))
            ->modalDescription(__('filament/pages/email-inbox.drawer.links.picker.description'))
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel(__('filament/pages/email-inbox.drawer.links.picker.submit'))
            ->schema([
                Select::make('target_type')
                    ->label(__('filament/pages/email-inbox.drawer.links.picker.type'))
                    ->options(array_column(array_map(fn (CrmEntity $entity): array => [$entity->value, __("filament/pages/email-inbox.drawer.links.types.{$entity->value}")], CrmEntity::linkable()), 1, 0))
                    ->default(CrmEntity::Company->value)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set): mixed => $set('target_id', null)),
                Select::make('target_id')
                    ->label(__('filament/pages/email-inbox.drawer.links.picker.record'))
                    ->searchPrompt(__('filament/pages/email-inbox.drawer.links.picker.search'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search, Get $get): array => $this->linkTargetOptions((string) $get('target_type'), $search))
                    ->getOptionLabelUsing(fn (mixed $value, Get $get): ?string => $this->findLinkTarget((string) $get('target_type'), (string) $value)?->name)
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->linkConversationTo((string) ($arguments['emailId'] ?? ''), (string) $data['target_type'], (string) $data['target_id']);
            });
    }

    protected function unlinkRecordAction(): Action
    {
        return Action::make('unlinkRecord')
            ->requiresConfirmation()
            ->color('danger')
            ->modalIcon('ri-link-unlink')
            ->modalHeading(fn (array $arguments): string => __('filament/pages/email-inbox.drawer.links.confirm.heading', ['name' => $this->linkTargetName($arguments)]))
            ->modalDescription(fn (array $arguments): string => __('filament/pages/email-inbox.drawer.links.confirm.description', ['name' => $this->linkTargetName($arguments)]))
            ->modalSubmitActionLabel(__('filament/pages/email-inbox.drawer.links.confirm.submit'))
            ->action(function (array $arguments): void {
                $emailId = (string) ($arguments['emailId'] ?? '');
                $type = (string) ($arguments['targetType'] ?? '');
                $email = $this->resolveWorkspaceEmail($emailId, 'view');
                $record = $this->findLinkTarget($type, (string) ($arguments['targetId'] ?? ''));

                abort_unless($email instanceof Email && $record !== null, 404);

                $changed = [];

                if (! $this->wroteLinks(function () use ($email, $record, &$changed): void {
                    $changed = resolve(UnlinkEmailFromRecord::class)->execute($this->readerUser(), $email, $record);
                })) {
                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__($this->stillOnRecord($email, $record) ? 'filament/pages/email-inbox.drawer.links.notifications.unlinked_teammate_keeps' : 'filament/pages/email-inbox.drawer.links.notifications.unlinked', ['name' => $record->name]))
                    ->actions([
                        Action::make('undo')
                            ->label(__('filament/pages/email-inbox.drawer.links.notifications.undo'))
                            ->link()
                            ->close()
                            ->dispatchTo(EmailAccessNotificationHandler::LIVEWIRE_ALIAS, 'relink-email-record')
                            ->eventData(['emailId' => $emailId, 'targetType' => $type, 'targetId' => (string) $record->getKey(), 'links' => $changed]),
                    ])
                    ->send();

                $this->dispatch('email:links-changed');
            });
    }

    private function stillOnRecord(Email $email, Company|People|Opportunity $record): bool
    {
        return is_string($email->conversation_key)
            && resolve(ConversationsQuery::class)->contains($this->readerUser(), $email->conversation_key, ConversationFilters::forRecord($record));
    }

    /** @param  Closure(): void  $write */
    private function wroteLinks(Closure $write): bool
    {
        try {
            $write();
        } catch (ValidationException $refused) {
            Notification::make()->danger()->title($refused->getMessage())->send();

            return false;
        }

        return true;
    }

    /** @param  array<array-key, mixed>  $links */
    protected function restoreConversationLinks(string $emailId, string $targetType, string $targetId, array $links): void
    {
        $email = $this->resolveWorkspaceEmail($emailId, 'view');
        $record = $this->findLinkTarget($targetType, $targetId);

        abort_unless($email instanceof Email && $record !== null, 404);

        $restored = false;

        if (! $this->wroteLinks(function () use ($email, $record, $links, &$restored): void {
            $restored = resolve(RestoreEmailRecordLinks::class)->execute($this->readerUser(), $email, $record, $links);
        })) {
            return;
        }

        if (! $restored) {
            Notification::make()
                ->info()
                ->title(__('filament/pages/email-inbox.drawer.links.notifications.nothing_restored', ['name' => $record->name]))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('filament/pages/email-inbox.drawer.links.notifications.linked', ['name' => $record->name]))
            ->send();

        $this->dispatch('email:links-changed');
    }

    protected function linkConversationTo(string $emailId, string $targetType, string $targetId): void
    {
        $email = $this->resolveWorkspaceEmail($emailId, 'view');
        $record = $this->findLinkTarget($targetType, $targetId);

        abort_unless($email instanceof Email && $record !== null, 404);

        if (! $this->wroteLinks(function () use ($email, $record): void {
            resolve(LinkEmailToRecord::class)->execute($this->readerUser(), $email, $record);
        })) {
            return;
        }

        Notification::make()
            ->success()
            ->title(__('filament/pages/email-inbox.drawer.links.notifications.linked', ['name' => $record->name]))
            ->send();

        $this->dispatch('email:links-changed');
    }

    #[On('email:links-changed')]
    public function refreshEmailLinks(): void
    {
        $this->refreshConversations();

        if ($this->selectedEmailId !== null && ! $this->selectedEmail instanceof Email) {
            $this->deselectEmail();
        }
    }

    /** @param  array<string, mixed>  $arguments */
    private function linkTargetName(array $arguments): string
    {
        return $this->findLinkTarget((string) ($arguments['targetType'] ?? ''), (string) ($arguments['targetId'] ?? ''))->name
            ?? __('filament/pages/email-inbox.drawer.links.this_record');
    }

    private function findLinkTarget(string $type, string $id): Company|People|Opportunity|null
    {
        return resolve(EmailLinkTargetsQuery::class)->find($this->readerUser(), $type, $id);
    }

    /** @return array<string, string> */
    private function linkTargetOptions(string $type, string $search): array
    {
        return resolve(EmailLinkTargetsQuery::class)->search($this->readerUser(), $type, $search);
    }

    private function canOpenRequestAccess(Email $email): bool
    {
        $user = $this->readerUser();

        return $user->cannot('viewBody', $email)
            && $user->can('requestAccess', $email)
            && ! $email->hasPendingAccessRequestFrom($user);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function emailForReaderAction(?Email $record, array $arguments, string $ability): ?Email
    {
        $emailId = $arguments['emailId'] ?? null;

        if (is_string($emailId) || is_int($emailId)) {
            return $this->resolveWorkspaceEmail((string) $emailId, $ability);
        }

        if (! $record instanceof Email) {
            return null;
        }

        if (! $this->readerUser()->can($ability, $record)) {
            return null;
        }

        return $record;
    }

    protected function resolveWorkspaceEmail(?string $emailId, string $ability): ?Email
    {
        if ($emailId === null) {
            return null;
        }

        $user = $this->readerUser();

        $email = Email::query()
            ->forWorkspace($user->current_workspace_id)
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
            ->inWorkspace($user->current_workspace_id)
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
            return view('email-integration::filament.actions.ai-summary', ['summary' => null, 'failed' => false]);
        }

        try {
            $summary = resolve(EmailThreadSummaryService::class)
                ->getSummary($thread, $this->readerUser());
        } catch (AiException|RequestException $exception) {
            report($exception);

            return view('email-integration::filament.actions.ai-summary', ['summary' => null, 'failed' => true]);
        }

        return view('email-integration::filament.actions.ai-summary', ['summary' => $summary, 'failed' => false]);
    }

    protected function approveAccessRequestAction(): Action
    {
        return Action::make('approveAccessRequest')
            ->requiresConfirmation()
            ->modalIcon('lucide-circle-check')
            ->modalIconColor('success')
            ->modalHeading(__('filament/pages/email-access-requests.actions.approve.modal_heading'))
            ->modalDescription(fn (array $arguments): string => __('filament/pages/email-access-requests.actions.approve.modal_description', [
                'name' => $this->requesterNameForOwnedRequest($this->readerAccessRequestId($arguments)),
            ]))
            ->modalSubmitActionLabel(__('filament/pages/email-access-requests.actions.approve.modal_submit_label'))
            ->color('success')
            ->action(function (array $arguments): void {
                $this->decideOwnedReaderAccessRequest($this->readerAccessRequestId($arguments), approve: true);
            });
    }

    protected function denyAccessRequestAction(): Action
    {
        return Action::make('denyAccessRequest')
            ->requiresConfirmation()
            ->modalHeading(__('filament/pages/email-access-requests.actions.deny.modal_heading'))
            ->modalDescription(fn (array $arguments): string => __('filament/pages/email-access-requests.actions.deny.modal_description', [
                'name' => $this->requesterNameForOwnedRequest($this->readerAccessRequestId($arguments)),
            ]))
            ->modalSubmitActionLabel(__('filament/pages/email-access-requests.actions.deny.modal_submit_label'))
            ->color('danger')
            ->action(function (array $arguments): void {
                $this->decideOwnedReaderAccessRequest($this->readerAccessRequestId($arguments), approve: false);
            });
    }

    protected function decideOwnedReaderAccessRequest(?string $requestId, bool $approve): void
    {
        $accessRequest = $this->ownedPendingRequest($requestId);

        if ($accessRequest === null) {
            return;
        }

        $decided = $approve
            ? resolve(ApproveEmailAccessRequestAction::class)->execute($accessRequest, $this->readerUser())
            : resolve(DenyEmailAccessRequestAction::class)->execute($accessRequest, $this->readerUser());

        if (! $decided) {
            return;
        }

        $this->afterOwnedReaderAccessRequestDecided($approve);
    }

    protected function afterOwnedReaderAccessRequestDecided(bool $approved): void
    {
        $this->notifyOwnedReaderAccessRequestDecision($approved);
        $this->refreshDatabaseNotifications();
    }

    protected function refreshDatabaseNotifications(): void
    {
        $this->dispatch('databaseNotificationsSent');
    }

    protected function notifyOwnedReaderAccessRequestDecision(bool $approved): void
    {
        Notification::make()
            ->success()
            ->title($approved
                ? __('filament/pages/email-access-requests.notifications.approved')
                : __('filament/pages/email-access-requests.notifications.denied'))
            ->send();
    }

    protected function ownedPendingRequest(?string $requestId): ?EmailAccessRequest
    {
        if ($requestId === null) {
            return null;
        }

        return EmailAccessRequest::query()
            ->with(['email', 'owner', 'requester'])
            ->whereKey($requestId)
            ->where('owner_id', $this->readerUser()->getKey())
            ->open()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function readerAccessRequestId(array $arguments): ?string
    {
        $requestId = $arguments['requestId'] ?? null;

        if (is_string($requestId) || is_int($requestId)) {
            return (string) $requestId;
        }

        return null;
    }

    protected function requesterNameForOwnedRequest(?string $requestId): string
    {
        if ($requestId === null) {
            return __('filament/pages/email-access-requests.actions.fallback_user');
        }

        return EmailAccessRequest::query()
            ->with('requester')
            ->whereKey($requestId)
            ->where('owner_id', $this->readerUser()->getKey())
            ->first()?->requester->name ?? __('filament/pages/email-access-requests.actions.fallback_user');
    }

    private function readerUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
