<?php

declare(strict_types=1);

namespace Relaticle\Chat\Models;

use App\Enums\MediaCollection;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
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
use Spatie\MediaLibrary\MediaCollections\File;
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
#[Unguarded]
final class AgentConversation extends Model implements HasMedia
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use InteractsWithMedia;

    public const string PURPOSE_SETUP = 'setup';

    public const string PURPOSE_EMAIL = 'email';

    public const string ATTACHMENTS_MEDIA_COLLECTION = MediaCollection::ChatAttachments->value;

    /** @var list<string> */
    private const array ATTACHMENT_MIME_TYPES = ['text/csv', 'text/plain', 'application/csv'];

    /** @var list<string> */
    private const array TEXT_ATTACHMENT_MIME_TYPES = ['message/rfc822', 'application/json', 'application/xml'];

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

    /** @return HasMany<PendingAction, $this> */
    public function pendingActions(): HasMany
    {
        return $this->hasMany(PendingAction::class, 'conversation_id');
    }

    /**
     * @return MorphMany<Media, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->media()->where('collection_name', self::ATTACHMENTS_MEDIA_COLLECTION);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::ATTACHMENTS_MEDIA_COLLECTION)
            ->acceptsFile(fn (File $file): bool => self::acceptsAttachmentMime($file->mimeType, namedAsText: true));
    }

    // finfo reads prose as mail, json or a script as easily as plain text, so a text name takes any text type.
    public static function acceptsAttachmentMime(string $mimeType, bool $namedAsText): bool
    {
        if (in_array($mimeType, self::ATTACHMENT_MIME_TYPES, true)) {
            return true;
        }

        return $namedAsText
            && (str_starts_with($mimeType, 'text/') || in_array($mimeType, self::TEXT_ATTACHMENT_MIME_TYPES, true));
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

    /** @param Builder<self> $query */
    #[Scope]
    protected function listable(Builder $query): void
    {
        $query->where(fn (Builder $query): Builder => $query->whereNull('purpose')->orWhere('purpose', '!=', self::PURPOSE_EMAIL));
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function forEmailConversation(Builder $query, string $key): void
    {
        $query->where('purpose', self::PURPOSE_EMAIL)->where('email_conversation_key', $key);
    }
}
