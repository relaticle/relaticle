<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;

/**
 * @extends Factory<MeetingAttendee>
 */
final class MeetingAttendeeFactory extends Factory
{
    protected $model = MeetingAttendee::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'email_address' => fake()->safeEmail(),
            'name' => fake()->name(),
            'response_status' => AttendeeResponseStatus::ACCEPTED,
            'is_organizer' => false,
            'is_self' => false,
        ];
    }
}
