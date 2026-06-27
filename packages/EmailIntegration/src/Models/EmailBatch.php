<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasTeam;
use App\Models\User;
use Database\Factories\EmailBatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Relaticle\EmailIntegration\Enums\EmailBatchStatus;

final class EmailBatch extends Model
{
    /** @use HasFactory<EmailBatchFactory> */
    use HasFactory;

    use HasTeam, HasUlids;

    protected $fillable = [
        'team_id',
        'user_id',
        'connected_account_id',
        'subject',
        'total_recipients',
        'sent_count',
        'failed_count',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => EmailBatchStatus::class,
            'total_recipients' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

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
     * @return HasMany<Email, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class, 'batch_id');
    }
}
