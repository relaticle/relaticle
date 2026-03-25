<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasAiSummary;
use App\Models\Concerns\HasTeam;
use Database\Factories\EmailThreadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EmailThread extends Model
{
    /**
     * @use HasFactory<EmailThreadFactory>
     */
    use HasAiSummary, HasFactory, HasTeam, HasUlids;

    protected $fillable = [
        'team_id',
        'connected_account_id',
        'thread_id',
        'subject',
        'email_count',
        'participant_count',
        'first_email_at',
        'last_email_at',
    ];

    protected $casts = [
        'first_email_at' => 'datetime',
        'last_email_at' => 'datetime',
        'email_count' => 'integer',
        'participant_count' => 'integer',
    ];

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id');
    }

    /**
     * @return HasMany<Email, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class, 'thread_id', 'thread_id')
            ->where('connected_account_id', $this->connected_account_id);
    }
}
