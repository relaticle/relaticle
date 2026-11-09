<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialiteProvider;
use Database\Factories\UserSocialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserSocialAccount extends Model
{
    /** @use HasFactory<UserSocialAccountFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->whereIn('provider_name', SocialiteProvider::available());
    }
}
