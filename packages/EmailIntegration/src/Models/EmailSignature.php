<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasWorkspace;
use App\Models\User;
use Database\Factories\EmailSignatureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'connected_account_id',
    'user_id',
    'name',
    'content_html',
    'is_default',
])]
final class EmailSignature extends Model
{
    /**
     * @use HasFactory<EmailSignatureFactory>
     */
    use HasFactory, HasUlids, HasWorkspace;

    protected static function newFactory(): EmailSignatureFactory
    {
        return EmailSignatureFactory::new();
    }

    /**
     * @param  Builder<EmailSignature>  $query
     * @return Builder<EmailSignature>
     */
    #[Scope]
    protected function defaultFor(Builder $query, string $accountId): Builder
    {
        return $query->where('connected_account_id', $accountId)->where('is_default', true);
    }

    /**
     * @return BelongsTo<ConnectedAccount, $this>
     */
    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class, 'connected_account_id');
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
            'is_default' => 'boolean',
        ];
    }
}
