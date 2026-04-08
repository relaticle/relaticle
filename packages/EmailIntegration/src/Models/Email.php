<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Company;
use App\Models\Concerns\HasTeam;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Database\Factories\EmailFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Observers\EmailObserver;

/**
 * @property string $id
 * @property string $team_id
 * @property string $user_id
 * @property string $connected_account_id
 * @property string|null $rfc_message_id
 * @property string|null $provider_message_id
 * @property string|null $thread_id
 * @property string|null $in_reply_to
 * @property string $subject
 * @property string|null $snippet
 * @property Carbon|null $sent_at
 * @property EmailDirection $direction
 * @property string|null $folder
 * @property EmailStatus $status
 * @property EmailPrivacyTier $privacy_tier
 * @property bool $has_attachments
 * @property bool $is_internal
 * @property Carbon|null $read_at
 * @property EmailCreationSource $creation_source
 */
#[ObservedBy(EmailObserver::class)]
final class Email extends Model
{
    /**
     * @use HasFactory<EmailFactory>
     */
    use HasFactory, HasTeam, HasUlids, SoftDeletes;

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
        'read_at',
        'creation_source',
    ];

    protected $attributes = [
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'status' => EmailStatus::SYNCED,
        'has_attachments' => false,
        'is_internal' => false,
        'creation_source' => EmailCreationSource::SYNC,
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
        'direction' => EmailDirection::class,
        'status' => EmailStatus::class,
        'privacy_tier' => EmailPrivacyTier::class,
        'creation_source' => EmailCreationSource::class,
        'has_attachments' => 'boolean',
        'is_internal' => 'boolean',
    ];

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
}
