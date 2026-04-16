<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use Database\Factories\ConnectedAccountSyncFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ConnectedAccountSync extends Model
{
    /**
     * @use HasFactory<ConnectedAccountSyncFactory>
     */
    use HasFactory, HasUlids;

    protected static function newFactory(): ConnectedAccountSyncFactory
    {
        return ConnectedAccountSyncFactory::new();
    }

    public $timestamps = false;

    protected $fillable = [
        'connected_account_id',
        'started_at',
        'completed_at',
        'emails_synced',
        'errors_encountered',
        'cursor_before',
        'cursor_after',
        'status',
        'error_details',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'emails_synced' => 'integer',
        'errors_encountered' => 'integer',
    ];

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id');
    }
}
