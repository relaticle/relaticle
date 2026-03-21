<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasTeam;
use App\Models\User;
use Database\Factories\ConnectedAccountFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailProvider;

final class ConnectedAccount extends Model
{
    /**
     * @use HasFactory<ConnectedAccountFactory>
     */
    use HasFactory, HasTeam, HasUlids, SoftDeletes;

    protected $fillable = [
        'team_id',
        'user_id',
        'provider',
        'provider_account_id',
        'email_address',
        'display_name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'capabilities',
        'sync_cursor',
        'last_synced_at',
        'status',
        'last_error',
        'sync_inbox',
        'sync_sent',
        'daily_send_limit',
        'hourly_send_limit',
    ];

    protected $casts = [
        'provider' => EmailProvider::class,
        'status' => EmailAccountStatus::class,
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'sync_inbox' => 'boolean',
        'sync_sent' => 'boolean',
        'capabilities' => 'array',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'daily_send_limit' => 'integer',
        'hourly_send_limit' => 'integer',
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
     * @return HasMany<Email, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    /**
     * @return HasMany<EmailThread, $this>
     */
    public function threads(): HasMany
    {
        return $this->hasMany(EmailThread::class);
    }

    /**
     * @return HasMany<ConnectedAccountSync, $this>
     */
    public function syncs(): HasMany
    {
        return $this->hasMany(ConnectedAccountSync::class);
    }

    /**
     * @return HasMany<EmailSignature, $this>
     */
    public function signatures(): HasMany
    {
        return $this->hasMany(EmailSignature::class);
    }

    // Helpers

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    public function isActive(): bool
    {
        return $this->status === EmailAccountStatus::ACTIVE && ! $this->isTokenExpired();
    }

    /**
     * @return Attribute<string, string>
     */
    protected function label(): Attribute
    {
        return Attribute::make(
            get: fn (): string => "{$this->provider->getLabel()} - $this->email_address",
        );
    }
}
