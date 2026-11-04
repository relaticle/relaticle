<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Relaticle\EmailIntegration\Actions\MarkEmailAsReadAction;
use Relaticle\EmailIntegration\Data\ConversationRow;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

/**
 * The whole conversation behind the open email, for hosts that also use {@see HasEmailReaderActions}.
 *
 * @property-read Email|null $selectedEmail
 * @property-read EloquentCollection<int, Email> $thread
 * @property-read EloquentCollection<string, Email> $expandedBodies
 * @property-read ConversationRow|null $selectedConversationRow
 * @property-read bool $selectedEmailUnavailable
 * @property-read array{previous: ConversationRow|null, next: ConversationRow|null} $adjacentConversations
 */
trait HasConversationReader
{
    /** @var list<string> */
    public array $expandedEmailIds = [];

    /** @return list<ConversationRow> */
    abstract protected function conversationRows(): array;

    /** @return EloquentCollection<int, Email> */
    #[Computed]
    public function thread(): EloquentCollection
    {
        $selected = $this->selectedEmail;

        if (! $selected instanceof Email) {
            return new EloquentCollection;
        }

        // A send just queued is not delivered yet, so no conversation holds it.
        $messages = is_string($selected->conversation_key)
            ? resolve(ConversationsQuery::class)->messages($this->readerUser(), $selected->conversation_key)
            : new EloquentCollection;

        return $messages->contains($selected) ? $messages : new EloquentCollection([$selected]);
    }

    /** @return EloquentCollection<string, Email> */
    #[Computed]
    public function expandedBodies(): EloquentCollection
    {
        $user = $this->readerUser();
        $expanded = [...$this->expandedEmailIds, $this->selectedEmailId];

        return $this->thread
            ->filter(fn (Email $message): bool => in_array($message->getKey(), $expanded, true) && $user->can('viewBody', $message))
            ->load(['body', 'attachments', 'labels', 'from'])
            ->keyBy(fn (Email $message): string => $message->getKey());
    }

    #[Computed]
    public function selectedConversationRow(): ?ConversationRow
    {
        $key = $this->selectedEmail?->conversation_key;

        return collect($this->conversationRows())->first(fn (ConversationRow $row): bool => $row->conversationKey === $key);
    }

    /** @return array{previous: ConversationRow|null, next: ConversationRow|null} */
    #[Computed]
    public function adjacentConversations(): array
    {
        $rows = $this->conversationRows();
        $key = $this->selectedEmail?->conversation_key;
        $index = array_search($key, array_map(fn (ConversationRow $row): string => $row->conversationKey, $rows), true);

        if (! is_int($index)) {
            return ['previous' => null, 'next' => null];
        }

        return ['previous' => $rows[$index - 1] ?? null, 'next' => $rows[$index + 1] ?? null];
    }

    // Crossing a page boundary is the list keyboard's job, so the ends of the loaded page stop here.
    public function openAdjacentConversation(string $direction): void
    {
        $row = match ($direction) {
            'previous' => $this->adjacentConversations['previous'],
            'next' => $this->adjacentConversations['next'],
            default => null,
        };

        if ($row instanceof ConversationRow) {
            $this->openConversationReader($row->viewerCopyEmailId);
        }
    }

    // Only a link can leave an id that opens nothing: a click that cannot open clears it.
    #[Computed]
    public function selectedEmailUnavailable(): bool
    {
        return $this->selectedEmailId !== null && ! $this->selectedEmail instanceof Email;
    }

    // A selection the client clears (the mobile back button) saves the reply draft too.
    public function updatedSelectedEmailId(): void
    {
        $this->dispatch('composer:dismiss-inline');
    }

    public function expandMessage(string $id): void
    {
        if (in_array($id, $this->expandedEmailIds, true) || ! $this->thread->contains(fn (Email $message): bool => $message->getKey() === $id)) {
            return;
        }

        $this->expandedEmailIds[] = $id;
        unset($this->expandedBodies);
    }

    protected function openConversationReader(string $id): void
    {
        unset($this->thread, $this->expandedBodies, $this->selectedConversationRow, $this->selectedEmailUnavailable, $this->adjacentConversations);
        $this->expandedEmailIds = [];

        if (! $this->openEmailReader($id)) {
            return;
        }

        $this->expandedEmailIds = $this->initiallyExpandedIds();
        $this->markThreadRead();
    }

    // Mail the viewer may read elsewhere is dropped; mail they may not see at all opens as not available.
    protected function openLinkedEmail(string $id): void
    {
        $this->openConversationReader($id);

        if ($this->selectedEmailId === null && ! $this->resolveWorkspaceEmail($id, 'view') instanceof Email) {
            $this->selectedEmailId = $id;
        }
    }

    /** @return list<string> */
    private function initiallyExpandedIds(): array
    {
        $user = $this->readerUser();
        $newest = $this->thread->last(fn (Email $message): bool => $user->can('viewBody', $message));

        $repliedTo = $newest?->in_reply_to === null
            ? null
            : $this->thread->first(fn (Email $message): bool => $message->rfc_message_id === $newest->in_reply_to);

        return array_values(array_unique(array_filter([$repliedTo?->getKey(), $newest?->getKey(), $this->selectedEmailId])));
    }

    private function markThreadRead(): void
    {
        $user = $this->readerUser();
        $markRead = resolve(MarkEmailAsReadAction::class);

        foreach ($this->thread as $message) {
            if ($user->can('viewBody', $message)) {
                $markRead->execute($message->getKey(), $user);
            }
        }
    }
}
