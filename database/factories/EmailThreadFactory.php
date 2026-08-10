<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailThread;

/**
 * @extends Factory<EmailThread>
 */
final class EmailThreadFactory extends Factory
{
    protected $model = EmailThread::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'connected_account_id' => ConnectedAccount::factory(),
            'thread_id' => 'thread-'.$this->faker->uuid(),
            'subject' => $this->faker->sentence(),
            'email_count' => 1,
            'participant_count' => 2,
            'first_email_at' => now()->subHour(),
            'last_email_at' => now(),
        ];
    }
}
