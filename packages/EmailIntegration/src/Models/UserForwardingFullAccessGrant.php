<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasTeam;
use App\Models\User;
use Database\Factories\UserForwardingFullAccessGrantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserForwardingFullAccessGrant extends Model
{
    /**
     * @use HasFactory<UserForwardingFullAccessGrantFactory>
     */
    use HasFactory, HasTeam, HasUlids;

    protected static function newFactory(): UserForwardingFullAccessGrantFactory
    {
        return UserForwardingFullAccessGrantFactory::new();
    }

    protected $fillable = [
        'user_id',
        'team_id',
        'granted_user_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_user_id');
    }
}
