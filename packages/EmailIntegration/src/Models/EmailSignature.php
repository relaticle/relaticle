<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\User;
use Database\Factories\EmailSignatureFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmailSignature extends Model
{
    /**
     * @use HasFactory<EmailSignatureFactory>
     */
    use HasFactory, HasUlids;

    protected static function newFactory(): EmailSignatureFactory
    {
        return EmailSignatureFactory::new();
    }

    protected $fillable = [
        'connected_account_id',
        'user_id',
        'name',
        'content_html',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
