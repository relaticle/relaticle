<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Relaticle\Chat\Models\AiCreditBalance;

final class ChatQaSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->where('email', 'chat-qa@relaticle.test')->delete();
        User::query()->where('email', 'other-team@relaticle.test')->delete();

        $user = User::factory()->withPersonalTeam()->create([
            'email' => 'chat-qa@relaticle.test',
            'password' => bcrypt('password'),
            'name' => 'Chat QA',
        ]);

        $team = $user->currentTeam;

        AiCreditBalance::query()->updateOrCreate(
            ['team_id' => $team->getKey()],
            [
                'credits_remaining' => 500,
                'credits_used' => 0,
                'period_starts_at' => now()->startOfMonth(),
                'period_ends_at' => now()->endOfMonth(),
            ],
        );

        Company::factory()->count(12)->for($team)->create([
            'account_owner_id' => $user->getKey(),
        ]);
        People::factory()->count(20)->for($team)->create();
        Opportunity::factory()->count(8)->for($team)->create();
        Task::factory()->count(15)->for($team)->create();

        // Cross-tenant isolation fixtures
        $otherUser = User::factory()->withPersonalTeam()->create([
            'email' => 'other-team@relaticle.test',
            'password' => bcrypt('password'),
        ]);
        Company::factory()->count(3)->for($otherUser->currentTeam)->create([
            'name' => 'OTHER-TEAM-ACME',
            'account_owner_id' => $otherUser->getKey(),
        ]);
    }
}
