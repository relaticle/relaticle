<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Enums\EmailBatchStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailBatch;

/**
 * @extends Factory<EmailBatch>
 */
final class EmailBatchFactory extends Factory
{
    protected $model = EmailBatch::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'connected_account_id' => ConnectedAccount::factory(),
            'subject' => $this->faker->sentence(),
            'total_recipients' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'status' => EmailBatchStatus::Queued,
        ];
    }
}
