<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasWorkspace;
use Database\Factories\PublicEmailDomainFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class PublicEmailDomain extends Model
{
    /**
     * @use HasFactory<PublicEmailDomainFactory>
     */
    use HasFactory, HasUlids, HasWorkspace;

    protected static function newFactory(): PublicEmailDomainFactory
    {
        return PublicEmailDomainFactory::new();
    }

    protected $fillable = [
        'workspace_id',
        'domain',
    ];
}
