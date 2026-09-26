<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\User;
use Database\Factories\TeamEmailBlocklistFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;

/**
 * @property string $workspace_id
 * @property EmailBlocklistType $type
 * @property EmailVisibilityEnforcement $enforcement_level
 * @property string $value
 * @property bool $include_subdomains
 * @property User|null $creator
 */
final class TeamEmailBlocklist extends Model
{
    protected $table = 'workspace_email_blocklists';

    /**
     * @use HasFactory<TeamEmailBlocklistFactory>
     */
    use HasFactory, HasUlids;

    protected static function newFactory(): TeamEmailBlocklistFactory
    {
        return TeamEmailBlocklistFactory::new();
    }

    protected $fillable = [
        'workspace_id',
        'type',
        'value',
        'enforcement_level',
        'include_subdomains',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => EmailBlocklistType::class,
            'enforcement_level' => EmailVisibilityEnforcement::class,
            'include_subdomains' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
