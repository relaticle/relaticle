<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Enums\CalendarVisibility;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;

/**
 * @extends Factory<Meeting>
 */
final class MeetingFactory extends Factory
{
    protected $model = Meeting::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $team = Team::factory()->create();
        $startsAt = fake()->dateTimeBetween('-10 days', '+30 days');
        $endsAt = (clone $startsAt)->modify('+30 minutes');

        return [
            'team_id' => $team->getKey(),
            'connected_account_id' => ConnectedAccount::factory()->for($team),
            'provider_event_id' => fake()->uuid(),
            'ical_uid' => fake()->uuid().'@google.com',
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'location' => fake()->optional()->address(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => false,
            'organizer_email' => fake()->safeEmail(),
            'organizer_name' => fake()->name(),
            'status' => CalendarEventStatus::CONFIRMED,
            'visibility' => CalendarVisibility::DEFAULT,
            'response_status' => AttendeeResponseStatus::ACCEPTED,
            'html_link' => 'https://calendar.google.com/event?eid='.fake()->uuid(),
        ];
    }

    public function private(): self
    {
        return $this->state(['visibility' => CalendarVisibility::PRIVATE]);
    }

    public function cancelled(): self
    {
        return $this->state(['status' => CalendarEventStatus::CANCELLED]);
    }

    public function declinedBySelf(): self
    {
        return $this->state(['response_status' => AttendeeResponseStatus::DECLINED]);
    }
}
