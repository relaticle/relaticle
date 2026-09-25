<?php

declare(strict_types=1);

namespace Relaticle\Chat\Models;

use App\Enums\MediaCollection;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property string $id
 * @property string|null $participant_type
 * @property string|null $participant_id
 * @property string|null $workspace_id
 * @property string|null $title
 * @property string|null $purpose
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(name: 'agent_conversations', keyType: 'string')]
#[WithoutIncrementing]
final class AgentConversation extends Model implements HasMedia
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use InteractsWithMedia;

    public const string PURPOSE_SETUP = 'setup';

    public const string ATTACHMENTS_MEDIA_COLLECTION = MediaCollection::ChatAttachments->value;

    /** @var list<string> */
    public const array ATTACHMENT_MIME_TYPES = ['text/csv', 'text/plain', 'application/csv'];

    protected $guarded = [];

    public function isSetup(): bool
    {
        return $this->purpose === self::PURPOSE_SETUP;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'participant_id');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<AgentConversationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AgentConversationMessage::class, 'conversation_id');
    }

    /**
     * @return MorphMany<Media, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->media()->where('collection_name', self::ATTACHMENTS_MEDIA_COLLECTION);
    }

    // The CSV readers take a filesystem path, so the collection pins the local
    // disk rather than following MEDIA_DISK.
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::ATTACHMENTS_MEDIA_COLLECTION)
            ->acceptsMimeTypes(self::ATTACHMENT_MIME_TYPES)
            ->useDisk('local');
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', (string) $user->getKey())
            ->where('workspace_id', $user->current_workspace_id);
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function setup(Builder $query): void
    {
        $query->where('purpose', self::PURPOSE_SETUP);
    }
}
