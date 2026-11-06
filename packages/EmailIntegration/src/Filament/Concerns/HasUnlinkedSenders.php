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
use Livewire\Attributes\Computed;
use Relaticle\EmailIntegration\Data\ConversationFilters;
use Relaticle\EmailIntegration\Data\ConversationRow;
use Relaticle\EmailIntegration\Data\UnlinkedSender;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Queries\UnlinkedSendersQuery;

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

        return resolve(ConversationsQuery::class)
            ->paginate($this->authUser(), ConversationView::Unlinked, new ConversationFilters(from: $this->expandedSender), perPage: 20)
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
            ->modalHeading(__('filament/pages/email-inbox.unlinked_senders.create_person.heading'))
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel(__('filament/pages/email-inbox.unlinked_senders.create_person.submit'))
            ->fillForm(fn (array $arguments): array => [
                'name' => (string) ($arguments['name'] ?? ''),
                'email' => (string) ($arguments['address'] ?? ''),
            ])
            ->schema([
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
                $person = resolve(CreatePeople::class)->execute($this->authUser(), [
                    'name' => (string) $data['name'],
                    'custom_fields' => [PeopleField::EMAILS->value => [$address]],
                ]);

                $this->afterSenderPersonSaved(__('filament/pages/email-inbox.unlinked_senders.notifications.created', ['name' => $person->name]));
            });
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
