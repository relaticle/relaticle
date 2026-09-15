<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasWorkspace;
use App\Models\User;
use Database\Factories\EmailBlocklistFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;

/**
 * @property EmailBlocklistType $type
 * @property string $value
 * @property bool $include_subdomains
 */
final class EmailBlocklist extends Model
{
    /**
     * @use HasFactory<EmailBlocklistFactory>
     */
    use HasFactory, HasUlids, HasWorkspace;

    protected static function newFactory(): EmailBlocklistFactory
    {
        return EmailBlocklistFactory::new();
    }

    protected $fillable = [
        'user_id',
        'workspace_id',
        'connected_account_id',
        'type',
        'value',
        'include_subdomains',
    ];

    protected function casts(): array
    {
        return [
            'type' => EmailBlocklistType::class,
            'include_subdomains' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }
}
