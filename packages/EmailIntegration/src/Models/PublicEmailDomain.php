<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasTeam;
use Database\Factories\PublicEmailDomainFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PublicEmailDomain extends Model
{
    /**
     * @use HasFactory<PublicEmailDomainFactory>
     */
    use HasFactory, HasTeam, HasUlids;

    protected static function newFactory(): PublicEmailDomainFactory
    {
        return PublicEmailDomainFactory::new();
    }

    protected $fillable = [
        'team_id',
        'domain',
    ];
}
