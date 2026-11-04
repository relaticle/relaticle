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
 * @property-read EloquentCollection<int, Email> $thread
 * @property-read EloquentCollection<string, Email> $expandedBodies
 * @property-read ConversationRow|null $selectedConversationRow
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
        $selected = $this->selectedEmail();

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
        $key = $this->selectedEmail()?->conversation_key;

        return collect($this->conversationRows())->first(fn (ConversationRow $row): bool => $row->conversationKey === $key);
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
        unset($this->thread, $this->expandedBodies, $this->selectedConversationRow);
        $this->expandedEmailIds = [];

        if (! $this->openEmailReader($id)) {
            return;
        }

        $this->expandedEmailIds = $this->initiallyExpandedIds();
        $this->markThreadRead();
    }

    /** @return list<string> */
    private function initiallyExpandedIds(): array
    {
        $newest = $this->thread->last();

        if (! $newest instanceof Email) {
            return [];
        }

        $repliedTo = $newest->in_reply_to === null
            ? null
            : $this->thread->first(fn (Email $message): bool => $message->rfc_message_id === $newest->in_reply_to);

        return array_values(array_filter([$repliedTo?->getKey(), $newest->getKey()]));
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
