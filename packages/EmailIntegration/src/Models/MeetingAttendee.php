<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models;

use App\Models\Company;
use App\Models\People;
use Database\Factories\MeetingAttendeeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;

/**
 * @property string $id
 * @property string $meeting_id
 * @property string $email_address
 * @property string|null $name
 * @property AttendeeResponseStatus|null $response_status
 * @property bool $is_organizer
 * @property bool $is_self
 * @property string|null $contact_id
 * @property string|null $company_id
 */
final class MeetingAttendee extends Model
{
    /** @use HasFactory<MeetingAttendeeFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'meeting_id',
        'email_address',
        'name',
        'response_status',
        'is_organizer',
        'is_self',
        'contact_id',
        'company_id',
    ];

    protected static function newFactory(): MeetingAttendeeFactory
    {
        return MeetingAttendeeFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_organizer' => 'boolean',
            'is_self' => 'boolean',
            'response_status' => AttendeeResponseStatus::class,
        ];
    }

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /** @return BelongsTo<People, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(People::class, 'contact_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
