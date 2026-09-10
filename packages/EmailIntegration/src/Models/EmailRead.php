<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\EmailReadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $email_id
 * @property string $user_id
 * @property CarbonInterface $read_at
 */
final class EmailRead extends Model
{
    /**
     * @use HasFactory<EmailReadFactory>
     */
    use HasFactory, HasUlids;

    protected static function newFactory(): EmailReadFactory
    {
        return EmailReadFactory::new();
    }

    protected $fillable = [
        'email_id',
        'user_id',
        'read_at',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }
}
