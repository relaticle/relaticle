<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\User;
use Database\Factories\EmailShareFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EmailShare extends Model
{
    /**
     * @use HasFactory<EmailShareFactory>
     */
    use HasFactory, HasUlids;

    protected static function newFactory(): EmailShareFactory
    {
        return EmailShareFactory::new();
    }

    protected $fillable = [
        'email_id',
        'shared_by',
        'shared_with',
        'tier',
    ];

    /**
     * @return BelongsTo<Email, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sharedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sharedWithUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_with');
    }
}
