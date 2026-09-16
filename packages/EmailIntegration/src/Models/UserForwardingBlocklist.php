<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasTeam;
use App\Models\User;
use Database\Factories\UserForwardingBlocklistFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;

/**
 * @property EmailBlocklistType $type
 * @property string $value
 */
final class UserForwardingBlocklist extends Model
{
    /**
     * @use HasFactory<UserForwardingBlocklistFactory>
     */
    use HasFactory, HasTeam, HasUlids;

    protected static function newFactory(): UserForwardingBlocklistFactory
    {
        return UserForwardingBlocklistFactory::new();
    }

    protected $fillable = [
        'user_id',
        'team_id',
        'type',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'type' => EmailBlocklistType::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
