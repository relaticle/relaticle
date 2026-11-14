<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use App\Actions\People\AddEmailAddressToPerson;
use App\Actions\People\CreatePeople;
use App\Enums\CrmEntity;
use App\Enums\CustomFields\PeopleField;
use App\Models\People;
use App\Models\User;
use App\Support\EmailAddress;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Livewire\Attributes\Computed;
use Relaticle\EmailIntegration\Actions\ApplyEmailLinkChanges;
use Relaticle\EmailIntegration\Actions\LinkEmailToRecord;
use Relaticle\EmailIntegration\Data\ConversationFilters;
use Relaticle\EmailIntegration\Data\ConversationRow;
use Relaticle\EmailIntegration\Data\UnlinkedSender;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Queries\UnlinkedSendersQuery;
use Relaticle\EmailIntegration\Support\PersonEmailMatcher;

/**
 * The Unlinked view grouped by sender, for the Emails page.
 *
 * @property-read ConversationView $activeView
 * @property-read bool $showsUnlinkedSenders
 * @property-read CursorPaginator<int, UnlinkedSender> $unlinkedSenders
 * @property-read list<ConversationRow> $expandedSenderRows
 */
trait HasUnlinkedSenders
{
    private const int SENDER_LINK_LIMIT = 100;

    public ?string $sendersCursor = null;

    public ?string $expandedSender = null;

    #[Computed]
    public function showsUnlinkedSenders(): bool
    {
        return $this->activeView === ConversationView::Unlinked && ! $this->isFiltering();
    }

    /** @return CursorPaginator<int, UnlinkedSender> */
    #[Computed]
    public function unlinkedSenders(): CursorPaginator
    {
        return resolve(UnlinkedSendersQuery::class)->paginate($this->authUser(), cursor: $this->sendersCursor);
    }

    /** @return list<ConversationRow> */
    #[Computed]
    public function expandedSenderRows(): array
    {
        if ($this->expandedSender === null) {
            return [];
        }

        $user = $this->authUser();
        $keys = resolve(UnlinkedSendersQuery::class)->conversationKeys($user, $this->expandedSender);

        return resolve(ConversationsQuery::class)
            ->paginate($user, ConversationView::Unlinked, new ConversationFilters(conversationKeys: $keys), perPage: 20)
            ->rows;
    }

    public function toggleSender(string $address): void
    {
        $this->expandedSender = $this->expandedSender === $address ? null : $address;
        unset($this->expandedSenderRows);
    }

    public function nextSendersPage(): void
    {
        $this->moveSendersCursor($this->unlinkedSenders->nextCursor()?->encode());
    }

    public function previousSendersPage(): void
    {
        $this->moveSendersCursor($this->unlinkedSenders->previousCursor()?->encode());
    }

    protected function createSenderPersonAction(): Action
    {
        return Action::make('createSenderPerson')
            ->label(__('filament/pages/email-inbox.unlinked_senders.create_person.label'))
            ->icon('ri-user-add-line')
            ->modalHeading(fn (array $arguments): string => ($holder = $this->senderHolder($arguments)) instanceof People
                ? __('filament/pages/email-inbox.unlinked_senders.create_person.exists_heading', ['name' => $holder->name])
                : __('filament/pages/email-inbox.unlinked_senders.create_person.heading'))
            ->modalDescription(fn (array $arguments): string => ($holder = $this->senderHolder($arguments)) instanceof People
                ? $this->senderHolderDescription((string) ($arguments['address'] ?? ''), $holder)
                : '')
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel(fn (array $arguments): string => ($holder = $this->senderHolder($arguments)) instanceof People
                ? __('filament/pages/email-inbox.unlinked_senders.create_person.link_to', ['name' => $holder->name])
                : __('filament/pages/email-inbox.unlinked_senders.create_person.submit'))
            ->fillForm(fn (array $arguments): array => [
                'name' => (string) ($arguments['name'] ?? ''),
                'email' => (string) ($arguments['address'] ?? ''),
            ])
            ->schema(fn (array $arguments): array => $this->senderHolder($arguments) instanceof People ? [] : [
                TextInput::make('name')
                    ->label(__('filament/pages/email-inbox.unlinked_senders.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label(__('filament/pages/email-inbox.unlinked_senders.fields.email'))
                    ->disabled(),
            ])
            ->action(function (array $data, array $arguments): void {
                $address = EmailAddress::canonicalize((string) ($arguments['address'] ?? ''));
                $holder = $this->senderHolder($arguments);

                if ($holder instanceof People) {
                    $this->linkSenderConversationsTo($address, $holder);

                    return;
                }

                $person = resolve(CreatePeople::class)->execute($this->authUser(), [
                    'name' => (string) $data['name'],
                    'custom_fields' => [PeopleField::EMAILS->value => [$address]],
                ]);

                $this->afterSenderPersonSaved(__('filament/pages/email-inbox.unlinked_senders.notifications.created', ['name' => $person->name]));
            });
    }

    /** @param  array<array-key, mixed>  $arguments */
    private function senderHolder(array $arguments): ?People
    {
        $address = (string) ($arguments['address'] ?? '');

        $workspaceId = (string) $this->authUser()->current_workspace_id;

        return once(fn (): ?People => resolve(PersonEmailMatcher::class)->firstMatching($address, $workspaceId));
    }

    private function senderHolderDescription(string $address, People $holder): string
    {
        $description = __('filament/pages/email-inbox.unlinked_senders.create_person.exists_description', ['name' => $holder->name, 'address' => $address]);
        $kept = $this->senderLinkPlan(EmailAddress::canonicalize($address), $holder)['kept_removed'];

        return $kept === 0
            ? $description
            : $description.' '.trans_choice('filament/pages/email-inbox.unlinked_senders.create_person.kept_removed', $kept, ['count' => $kept, 'name' => $holder->name]);
    }

    private function linkSenderConversationsTo(string $address, People $person): void
    {
        resolve(ApplyEmailLinkChanges::class)->execute($this->authUser(), $this->senderLinkPlan($address, $person)['pairs'], resolve(LinkEmailToRecord::class));

        $this->afterSenderPersonSaved(__('filament/pages/email-inbox.drawer.links.notifications.linked', ['name' => $person->name]));
    }

    /**
     * The sender's conversations to link, minus those removed from the person by the user or on a copy they hold: an unlink stays.
     *
     * @return array{pairs: list<array{email: Email, record: People}>, kept_removed: int}
     */
    private function senderLinkPlan(string $address, People $person): array
    {
        $user = $this->authUser();
        $keys = resolve(UnlinkedSendersQuery::class)->conversationKeys($user, $address, self::SENDER_LINK_LIMIT);
        $removed = Email::query()
            ->withoutGlobalScopes()
            ->where('emails.workspace_id', $user->current_workspace_id)
            ->whereIn('emails.conversation_key', $keys)
            ->whereExists(fn (QueryBuilder $suppression): QueryBuilder => $suppression
                ->from('email_link_suppressions')
                ->whereColumn('email_link_suppressions.email_id', 'emails.id')
                ->where('email_link_suppressions.emailable_type', $person->getMorphClass())
                ->where('email_link_suppressions.emailable_id', $person->getKey())
                ->where(fn (QueryBuilder $mine): QueryBuilder => $mine
                    ->where('email_link_suppressions.user_id', $user->getKey())
                    ->orWhere('emails.user_id', $user->getKey())))
            ->distinct()
            ->pluck('emails.conversation_key')
            ->all();
        $copyIds = resolve(ConversationsQuery::class)->reachedCopies($user, array_values(array_diff($keys, $removed)))->unique('conversation_key')->pluck('id')->all();

        return [
            'pairs' => array_values(Email::query()->whereKey($copyIds)->get()
                ->map(fn (Email $email): array => ['email' => $email, 'record' => $person])
                ->all()),
            'kept_removed' => count($removed),
        ];
    }

    protected function linkSenderToPersonAction(): Action
    {
        return Action::make('linkSenderToPerson')
            ->label(__('filament/pages/email-inbox.unlinked_senders.link_existing.label'))
            ->icon('ri-link')
            ->modalHeading(fn (array $arguments): string => __('filament/pages/email-inbox.unlinked_senders.link_existing.heading', ['address' => (string) ($arguments['address'] ?? '')]))
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel(__('filament/pages/email-inbox.unlinked_senders.link_existing.submit'))
            ->schema([
                Select::make('person_id')
                    ->label(__('filament/pages/email-inbox.unlinked_senders.fields.person'))
                    ->searchPrompt(__('filament/pages/email-inbox.drawer.links.picker.search'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => $this->linkTargetOptions(CrmEntity::People->value, $search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => $this->findLinkTarget(CrmEntity::People->value, (string) $value)?->name)
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                $person = $this->findLinkTarget(CrmEntity::People->value, (string) $data['person_id']);

                abort_unless($person instanceof People, 404);

                $address = (string) ($arguments['address'] ?? '');
                resolve(AddEmailAddressToPerson::class)->execute($this->authUser(), $person, $address);

                $this->afterSenderPersonSaved(__('filament/pages/email-inbox.unlinked_senders.notifications.added', ['address' => $address, 'name' => $person->name]));
            });
    }

    private function afterSenderPersonSaved(string $message): void
    {
        Notification::make()->success()->title($message)->send();

        $this->expandedSender = null;
        unset($this->unlinkedSenders, $this->expandedSenderRows);
        $this->refreshConversations();
    }

    private function moveSendersCursor(?string $cursor): void
    {
        if ($cursor === null) {
            return;
        }

        $this->sendersCursor = $cursor;
        $this->expandedSender = null;
        unset($this->unlinkedSenders, $this->expandedSenderRows);
    }

    abstract private function authUser(): User;
}
