<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasTeam;
use App\Models\Team;
use Database\Factories\TeamForwardingAddressFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $team_id
 * @property string $local_part
 */
final class TeamForwardingAddress extends Model
{
    /**
     * @use HasFactory<TeamForwardingAddressFactory>
     */
    use HasFactory, HasTeam, HasUlids;

    protected static function newFactory(): TeamForwardingAddressFactory
    {
        return TeamForwardingAddressFactory::new();
    }

    protected $fillable = [
        'team_id',
        'local_part',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function fullAddress(): string
    {
        $domain = (string) config('email-integration.inbound.domain');

        return "{$this->local_part}@{$domain}";
    }
}
