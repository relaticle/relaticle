<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Concerns\HasWorkspace;
use Carbon\CarbonImmutable;
use Database\Factories\EmailLinkSuppressionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $email_id
 * @property string $emailable_type
 * @property string $emailable_id
 * @property string|null $user_id
 * @property CarbonImmutable $created_at
 */
#[Fillable(['workspace_id', 'email_id', 'emailable_type', 'emailable_id', 'user_id'])]
final class EmailLinkSuppression extends Model
{
    /**
     * @use HasFactory<EmailLinkSuppressionFactory>
     */
    use HasFactory, HasUlids, HasWorkspace;

    public const UPDATED_AT = null;

    protected static function newFactory(): EmailLinkSuppressionFactory
    {
        return EmailLinkSuppressionFactory::new();
    }

    /**
     * @return BelongsTo<Email, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function emailable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<EmailLinkSuppression>  $query
     * @return Builder<EmailLinkSuppression>
     */
    #[Scope]
    protected function matchingConversationOf(Builder $query, Email $email): Builder
    {
        $copies = Email::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $email->workspace_id)
            ->where(fn (Builder $same): Builder => $same
                ->whereKey($email->getKey())
                ->when($email->conversation_key, fn (Builder $keyed, string $key): Builder => $keyed->orWhere('conversation_key', $key))
                ->when($email->rfc_message_id, fn (Builder $message, string $id): Builder => $message->orWhere('rfc_message_id', $id)))
            ->select('id');

        return $query
            ->where($query->qualifyColumn('workspace_id'), $email->workspace_id)
            ->whereIn($query->qualifyColumn('email_id'), $copies);
    }
}
