<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Company;
use App\Models\People;
use App\Models\User;
use App\Support\LikePattern;
use Database\Factories\EmailParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;

/**
 * @property string $email_address
 * @property string|null $name
 * @property EmailParticipantRole $role
 */
#[Fillable([
    'email_id',
    'email_address',
    'name',
    'role',
    'contact_id',
    'company_id',
])]
final class EmailParticipant extends Model
{
    /**
     * @use HasFactory<EmailParticipantFactory>
     */
    use HasFactory, HasUlids;

    protected static function newFactory(): EmailParticipantFactory
    {
        return EmailParticipantFactory::new();
    }

    /**
     * @return BelongsTo<Email, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /**
     * @return BelongsTo<People, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(People::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * A name or address fragment, never another member's BCC row.
     *
     * @param  Builder<EmailParticipant>  $query
     * @return Builder<EmailParticipant>
     */
    #[Scope]
    protected function matchingSearch(Builder $query, User $viewer, string $term): Builder
    {
        $needle = '%'.LikePattern::escape($term).'%';

        return $query
            ->where(fn (Builder $roleGate): Builder => $roleGate
                ->where('email_participants.role', '!=', EmailParticipantRole::BCC)
                ->orWhereHas('email', fn (Builder $ownedEmail): Builder => $ownedEmail->where('emails.user_id', $viewer->getKey())))
            ->where(fn (Builder $match): Builder => $match
                ->where('email_participants.name', 'ilike', $needle)
                ->orWhere('email_participants.email_address', 'ilike', $needle));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => EmailParticipantRole::class,
        ];
    }
}
