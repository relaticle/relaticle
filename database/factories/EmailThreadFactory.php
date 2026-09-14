<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Workspace;
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
            'workspace_id' => Workspace::factory(),
            'connected_account_id' => ConnectedAccount::factory(),
            'thread_id' => 'thread-'.fake()->uuid(),
            'subject' => fake()->sentence(),
            'email_count' => 1,
            'participant_count' => 2,
            'first_email_at' => now()->subHour(),
            'last_email_at' => now(),
        ];
    }
}
