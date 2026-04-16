<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Company;
use App\Models\People;
use Database\Factories\EmailParticipantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;

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

    protected $fillable = [
        'email_id',
        'email_address',
        'name',
        'role',
        'contact_id',
        'company_id',
    ];

    protected $casts = [
        'role' => EmailParticipantRole::class,
    ];

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
}
