<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class ChatAttachment
{
    public function __construct(public Media $media) {}

    public static function find(User $user, string $id): ?self
    {
        $media = Media::query()
            ->where('workspace_id', $user->current_workspace_id)
            ->where('collection_name', AgentConversation::ATTACHMENTS_MEDIA_COLLECTION)
            ->where('uuid', $id)
            ->whereHasMorph('model', [AgentConversation::class], fn (Builder $query) => $query->ownedBy($user))
            ->first();

        return $media instanceof Media ? new self($media) : null;
    }

    public function id(): string
    {
        return (string) $this->media->uuid;
    }

    public function name(): string
    {
        return (string) $this->media->getCustomProperty('original_name', $this->media->file_name);
    }

    public function rowCount(): int
    {
        return (int) $this->media->getCustomProperty('row_count', 0);
    }

    /** @return list<string> */
    public function header(): array
    {
        $header = $this->media->getCustomProperty('header', []);

        return is_array($header) ? array_values(array_map(strval(...), $header)) : [];
    }

    public function conversationId(): string
    {
        return (string) $this->media->model_id;
    }

    public function conversation(): ?AgentConversation
    {
        $model = $this->media->model;

        return $model instanceof AgentConversation ? $model : null;
    }

    public function isSent(): bool
    {
        return $this->media->getCustomProperty('sent_at') !== null;
    }

    public function importIdFor(ImportEntityType $type): ?string
    {
        $id = $this->media->getCustomProperty("imports.{$type->value}");

        return is_string($id) ? $id : null;
    }

    public function absolutePath(): string
    {
        return $this->media->getPath();
    }

    public function fileExists(): bool
    {
        return Storage::disk($this->media->disk)->exists($this->media->getPathRelativeToRoot());
    }

    /** @return array{id: string, name: string, row_count: int} */
    public function meta(): array
    {
        return ['id' => $this->id(), 'name' => $this->name(), 'row_count' => $this->rowCount()];
    }
}
