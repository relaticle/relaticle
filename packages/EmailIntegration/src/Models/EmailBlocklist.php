<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasTeam;
use App\Models\User;
use Database\Factories\EmailBlocklistFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;

final class EmailBlocklist extends Model
{
    /**
     * @use HasFactory<EmailBlocklistFactory>
     */
    use HasFactory, HasTeam, HasUlids;

    protected static function newFactory(): EmailBlocklistFactory
    {
        return EmailBlocklistFactory::new();
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
