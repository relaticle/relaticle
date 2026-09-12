<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Company;
use App\Models\Concerns\HasTeam;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\EmailFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPriority;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Scopes\ActiveAccountScope;
use Relaticle\EmailIntegration\Observers\EmailObserver;
use Relaticle\EmailIntegration\Support\EmailHtmlSanitizer;

/**
 * @property string $id
 * @property string $team_id
 * @property string $user_id
 * @property string|null $connected_account_id
 * @property string|null $rfc_message_id
 * @property string|null $provider_message_id
 * @property string|null $thread_id
 * @property string|null $in_reply_to
 * @property string $subject
 * @property string|null $snippet
 * @property CarbonInterface|null $sent_at
 * @property EmailDirection $direction
 * @property EmailFolder|null $folder
 * @property EmailStatus $status
 * @property EmailPrivacyTier $privacy_tier
 * @property bool $has_attachments
 * @property bool $is_internal
 * @property EmailCreationSource $creation_source
 * @property string|null $batch_id
 * @property CarbonInterface|null $scheduled_for
 * @property string|null $last_error
 * @property int $attempts
 * @property CarbonInterface|null $linked_at
 * @property EmailPriority $priority
 */
#[ObservedBy(EmailObserver::class)]
#[ScopedBy([ActiveAccountScope::class])]
final class Email extends Model
{
    /**
     * @use HasFactory<EmailFactory>
     */
    use HasFactory, HasTeam, HasUlids, SoftDeletes;

    protected static function newFactory(): EmailFactory
    {
        return EmailFactory::new();
    }

    protected $fillable = [
        'team_id',
        'user_id',
        'connected_account_id',
        'rfc_message_id',
        'provider_message_id',
        'thread_id',
        'in_reply_to',
        'subject',
        'snippet',
        'sent_at',
        'direction',
        'folder',
        'status',
        'privacy_tier',
        'has_attachments',
        'is_internal',
        'creation_source',
        'batch_id',
        'scheduled_for',
        'last_error',
        'attempts',
        'linked_at',
        'priority',
    ];

    protected $attributes = [
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'status' => EmailStatus::SYNCED,
        'has_attachments' => false,
        'is_internal' => false,
        'creation_source' => EmailCreationSource::SYNC,
    ];

    /**
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    #[Scope]
    protected function forTeam(Builder $query, string $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }

    /**
     * Scope to mail that has actually been sent (excludes queued/sending/failed outbound
     * mail, which lives in the Outbox and has no sent_at yet).
     *
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    #[Scope]
    protected function sent(Builder $query): Builder
    {
        return $query->where('direction', EmailDirection::OUTBOUND)
            ->whereNotNull('sent_at');
    }

    /**
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    #[Scope]
    protected function inbox(Builder $query): Builder
    {
        return $query->where('direction', EmailDirection::INBOUND);
    }

    /**
     * Expose a per-viewer `is_read` boolean so list rows can render unread state
     * for the authenticated user (not the owner's global state).
     *
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    #[Scope]
    protected function withReadStateFor(Builder $query, string $userId): Builder
    {
        return $query->withExists(['reads as is_read' => fn (Builder $readQuery): Builder => $readQuery->where('user_id', $userId)]);
    }

    /**
     * Inbound emails the given user has not yet read.
     *
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    #[Scope]
    protected function unreadFor(Builder $query, string $userId): Builder
    {
        return $query->where('direction', EmailDirection::INBOUND)
            ->whereDoesntHave('reads', fn (Builder $readQuery): Builder => $readQuery->where('user_id', $userId));
    }

    // Relations

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }

    /**
     * @return HasOne<EmailBody, $this>
     */
    public function body(): HasOne
    {
        return $this->hasOne(EmailBody::class);
    }

    /**
     * @return HasMany<EmailParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(EmailParticipant::class);
    }

    /**
     * @return HasMany<EmailParticipant, $this>
     */
    public function from(): HasMany
    {
        return $this->hasMany(EmailParticipant::class)->where('role', 'from');
    }

    public function fromParticipant(): ?EmailParticipant
    {
        return $this->participants->firstWhere('role', EmailParticipantRole::FROM);
    }

    /**
     * @return EloquentCollection<int, EmailParticipant>
     */
    public function toParticipants(): EloquentCollection
    {
        return $this->participants->where('role', EmailParticipantRole::TO);
    }

    /**
     * @return EloquentCollection<int, EmailParticipant>
     */
    public function ccParticipants(): EloquentCollection
    {
        return $this->participants->where('role', EmailParticipantRole::CC);
    }

    /**
     * The rule-based category stamped at sync. StoreEmailAction writes
     * `source = system`. The unmatched Other fallback is stored, not shown.
     */
    public function categoryLabel(): ?EmailLabel
    {
        $label = $this->labels->firstWhere('source', 'system');

        if (! $label instanceof EmailLabel) {
            return null;
        }

        $category = EmailCategory::tryFrom($label->label);

        if (! $category instanceof EmailCategory || ! $category->isVisible()) {
            return null;
        }

        return $label;
    }

    /**
     * Name of the mailbox that imported this email, for list-row "via …" copy.
     * Prefer the provider display name on the connected account, then the
     * teammate who linked that mailbox, then the address itself.
     */
    public function mailboxViaName(): ?string
    {
        $account = $this->connectedAccount;

        if ($account !== null) {
            if (filled($account->display_name)) {
                return $account->display_name;
            }

            if (filled($account->user?->name)) {
                return $account->user->name;
            }

            return $account->email_address ?: null;
        }

        return $this->user?->name;
    }

    /**
     * Connected mailboxes that hold a visible copy of this message. Email is
     * the mailbox address, never the Relaticle login.
     *
     * @var list<array{name: string, mailbox_email: string}>
     */
    public array $accessMailboxes = [];

    /**
     * @return EloquentCollection<int, EmailAttachment>
     */
    public function inlineAttachments(): EloquentCollection
    {
        return $this->attachments->where('is_inline', true);
    }

    /**
     * @return EloquentCollection<int, EmailAttachment>
     */
    public function downloadAttachments(): EloquentCollection
    {
        return $this->attachments->where('is_inline', false);
    }

    public function sanitizedBodyHtml(): ?string
    {
        return EmailHtmlSanitizer::sanitize($this->body?->body_html, $this->inlineAttachments());
    }

    /**
     * HTML to append when this message is replied to or forwarded. Prefers the
     * stored HTML. A plain-text-only body is escaped so the original is not dropped.
     */
    public function quotedBodyHtml(): ?string
    {
        $html = $this->body?->body_html;

        if (filled($html)) {
            return $html;
        }

        $text = $this->body?->body_text;

        if (blank($text)) {
            return null;
        }

        return nl2br(e($text), false);
    }

    /**
     * Reply-all recipient addresses: the original sender PLUS the to/cc recipients
     * (never bcc), excluding the replying user's own account address. De-duplicated
     * case-insensitively. Operates on the loaded `participants` relation.
     *
     * @return list<non-empty-string>
     */
    public function replyAllRecipients(?string $excludeAddress = null): array
    {
        $normalizedExclude = $excludeAddress !== null ? strtolower($excludeAddress) : null;

        return array_values($this->participants
            ->whereIn('role', [EmailParticipantRole::FROM, EmailParticipantRole::TO, EmailParticipantRole::CC])
            ->map(fn (EmailParticipant $participant): string => (string) $participant->email_address)
            ->filter(fn (string $address): bool => $address !== '' && strtolower($address) !== $normalizedExclude)
            ->unique(fn (string $address): string => strtolower($address))
            ->all());
    }

    /**
     * @return HasMany<EmailAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }

    /**
     * @return HasMany<EmailShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(EmailShare::class);
    }

    /**
     * @return HasMany<EmailAccessRequest, $this>
     */
    public function accessRequests(): HasMany
    {
        return $this->hasMany(EmailAccessRequest::class);
    }

    public function hasPendingAccessRequestFrom(User $viewer): bool
    {
        return $this->accessRequests()
            ->where('requester_id', $viewer->getKey())
            ->where('status', EmailAccessRequestStatus::PENDING)
            ->exists();
    }

    /**
     * @return HasMany<EmailRead, $this>
     */
    public function reads(): HasMany
    {
        return $this->hasMany(EmailRead::class);
    }

    /**
     * @return HasMany<EmailLabel, $this>
     */
    public function labels(): HasMany
    {
        return $this->hasMany(EmailLabel::class);
    }

    /**
     * @return MorphToMany<People, $this>
     */
    public function people(): MorphToMany
    {
        return $this->morphedByMany(People::class, 'emailable');
    }

    /**
     * @return MorphToMany<Company, $this>
     */
    public function companies(): MorphToMany
    {
        return $this->morphedByMany(Company::class, 'emailable');
    }

    /**
     * @return MorphToMany<Opportunity, $this>
     */
    public function opportunities(): MorphToMany
    {
        return $this->morphedByMany(Opportunity::class, 'emailable');
    }

    /**
     * @return BelongsTo<EmailBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(EmailBatch::class, 'batch_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'direction' => EmailDirection::class,
            'folder' => EmailFolder::class,
            'status' => EmailStatus::class,
            'privacy_tier' => EmailPrivacyTier::class,
            'creation_source' => EmailCreationSource::class,
            'has_attachments' => 'boolean',
            'is_internal' => 'boolean',
            'scheduled_for' => 'datetime',
            'attempts' => 'integer',
            'linked_at' => 'datetime',
            'priority' => EmailPriority::class,
        ];
    }
}
