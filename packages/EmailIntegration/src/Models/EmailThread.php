<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasAiSummary;
use App\Models\Concerns\HasTeam;
use Carbon\CarbonInterface;
use Database\Factories\EmailThreadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonInterface|null $first_email_at
 * @property CarbonInterface|null $last_email_at
 * @property int $email_count
 * @property int $participant_count
 */
final class EmailThread extends Model
{
    /**
     * @use HasFactory<EmailThreadFactory>
     */
    use HasAiSummary, HasFactory, HasTeam, HasUlids;

    protected static function newFactory(): EmailThreadFactory
    {
        return EmailThreadFactory::new();
    }

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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_email_at' => 'datetime',
            'last_email_at' => 'datetime',
            'email_count' => 'integer',
            'participant_count' => 'integer',
        ];
    }
}
