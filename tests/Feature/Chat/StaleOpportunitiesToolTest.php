<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Tools\Opportunity\ListOpportunitiesTool;

mutates(ListOpportunitiesTool::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Auth::guard('web')->setUser($this->user);
});

it('returns only opportunities with no activity in the last 30 days when stale_days is set', function (): void {
    // Create the stale opportunity 40 days in the past — this also backdates its creation activity log entry
    $this->travelTo(now()->subDays(40));
    $staleOpp = Opportunity::factory()->for($this->team)->create(['name' => 'Stale Deal']);

    // Create the active opportunity now, then add a recent manual activity entry
    $this->travelBack();
    $activeOpp = Opportunity::factory()->for($this->team)->create(['name' => 'Active Deal']);

    $tool = new ListOpportunitiesTool;
    $response = $tool->handle(new Request(['stale_days' => 30]));

    $data = json_decode($response, true);
    $items = is_array($data) && isset($data['data']) ? $data['data'] : $data;

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['name'])->toBe('Stale Deal');
});

it('includes opportunities created long ago with no recent activity when stale_days is set', function (): void {
    // Both opportunities created 40 days ago (backdate)
    $this->travelTo(now()->subDays(40));
    $neverActive = Opportunity::factory()->for($this->team)->create(['name' => 'Never Active']);

    // Active one also created in the past but gets a recent activity entry
    $activeOpp = Opportunity::factory()->for($this->team)->create(['name' => 'Active Deal']);

    // Travel back and add a recent activity for the active opportunity
    $this->travelBack();
    DB::table('activity_log')->insert([
        'log_name' => 'crm',
        'description' => 'updated',
        'subject_type' => 'opportunity',
        'subject_id' => (string) $activeOpp->getKey(),
        'team_id' => $this->team->getKey(),
        'event' => 'updated',
        'properties' => '[]',
        'created_at' => now()->subDays(2)->toDateTimeString(),
        'updated_at' => now()->subDays(2)->toDateTimeString(),
    ]);

    $tool = new ListOpportunitiesTool;
    $response = $tool->handle(new Request(['stale_days' => 30]));

    $data = json_decode($response, true);
    $items = is_array($data) && isset($data['data']) ? $data['data'] : $data;

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['name'])->toBe('Never Active');
});

it('still treats an opportunity as stale when a different team has a recent activity_log entry for the same subject_id', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $otherTeam = $otherUser->currentTeam;

    // Stale opportunity created 40 days ago on the current team
    $this->travelTo(now()->subDays(40));
    $staleOpp = Opportunity::factory()->for($this->team)->create(['name' => 'Cross-Team Stale Deal']);
    $this->travelBack();

    // Insert a recent activity_log row referencing the same subject_id but for a different team
    DB::table('activity_log')->insert([
        'log_name' => 'crm',
        'description' => 'updated',
        'subject_type' => 'opportunity',
        'subject_id' => (string) $staleOpp->getKey(),
        'team_id' => $otherTeam->getKey(),
        'event' => 'updated',
        'properties' => '[]',
        'created_at' => now()->subDays(1)->toDateTimeString(),
        'updated_at' => now()->subDays(1)->toDateTimeString(),
    ]);

    $tool = new ListOpportunitiesTool;
    $response = $tool->handle(new Request(['stale_days' => 30]));

    $data = json_decode($response, true);
    $items = is_array($data) && isset($data['data']) ? $data['data'] : $data;

    expect($items)->toHaveCount(1)
        ->and($items[0]['attributes']['name'])->toBe('Cross-Team Stale Deal');
});
