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
use Illuminate\Database\Query\Builder as QueryBuilder;

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
     * Suppressions on the email itself or on another copy of its conversation in the same holder's mail.
     *
     * @param  Builder<EmailLinkSuppression>  $query
     * @return Builder<EmailLinkSuppression>
     */
    #[Scope]
    protected function heldAlongside(Builder $query, Email $email): Builder
    {
        $copies = Email::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $email->workspace_id)
            ->where(fn (Builder $same): Builder => $same
                ->whereKey($email->getKey())
                ->when($email->conversation_key, fn (Builder $keyed, string $key): Builder => $keyed->orWhere(fn (Builder $held): Builder => $held
                    ->where('conversation_key', $key)
                    ->where('user_id', $email->user_id))))
            ->select('id');

        return $query
            ->where($query->qualifyColumn('workspace_id'), $email->workspace_id)
            ->whereIn($query->qualifyColumn('email_id'), $copies);
    }

    /**
     * Suppressions no pin overrides: a manual link of the same record on any copy of the suppressed copy's conversation wins.
     *
     * @param  Builder<EmailLinkSuppression>  $query
     * @return Builder<EmailLinkSuppression>
     */
    #[Scope]
    protected function inForce(Builder $query): Builder
    {
        return $query->whereNotExists(fn (QueryBuilder $pin): QueryBuilder => $pin
            ->from('emailables as pins')
            ->join('emails as pinned_copies', 'pinned_copies.id', '=', 'pins.email_id')
            ->join('emails as suppressed_on', 'suppressed_on.id', '=', $query->qualifyColumn('email_id'))
            ->where('pins.link_source', 'manual')
            ->whereColumn('pins.emailable_type', $query->qualifyColumn('emailable_type'))
            ->whereColumn('pins.emailable_id', $query->qualifyColumn('emailable_id'))
            ->whereColumn('pinned_copies.workspace_id', 'suppressed_on.workspace_id')
            ->where(fn (QueryBuilder $same): QueryBuilder => $same
                ->whereColumn('pinned_copies.id', 'suppressed_on.id')
                ->orWhereColumn('pinned_copies.conversation_key', 'suppressed_on.conversation_key')));
    }
}
