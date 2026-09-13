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
        User::query()->where('email', 'other-workspace@relaticle.test')->delete();

        $user = User::factory()->withPersonalWorkspace()->create([
            'email' => 'chat-qa@relaticle.test',
            'password' => bcrypt('password'),
            'name' => 'Chat QA',
        ]);

        $workspace = $user->currentWorkspace;

        AiCreditBalance::query()->updateOrCreate(
            ['workspace_id' => $workspace->getKey()],
            [
                'credits_remaining' => 500,
                'credits_used' => 0,
                'period_starts_at' => now()->startOfMonth(),
                'period_ends_at' => now()->endOfMonth(),
            ],
        );

        Company::factory()->count(12)->for($workspace)->create([
            'account_owner_id' => $user->getKey(),
        ]);
        People::factory()->count(20)->for($workspace)->create();
        Opportunity::factory()->count(8)->for($workspace)->create();
        Task::factory()->count(15)->for($workspace)->create();

        // Cross-tenant isolation fixtures
        $otherUser = User::factory()->withPersonalWorkspace()->create([
            'email' => 'other-workspace@relaticle.test',
            'password' => bcrypt('password'),
        ]);
        Company::factory()->count(3)->for($otherUser->currentWorkspace)->create([
            'name' => 'OTHER-WORKSPACE-ACME',
            'account_owner_id' => $otherUser->getKey(),
        ]);
    }
}
