<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Tools\GetCrmSummaryTool;

mutates(GetCrmSummaryTool::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
});

/**
 * The instant both tests below read the summary at: Sunday night in UTC, but already
 * Monday morning in Tokyo. Tokyo's week has therefore just begun (it starts at
 * 2026-08-16 15:00 UTC) while the UTC reader is still in the week that began on
 * 2026-08-10. A company created between those two boundaries belongs to "this week"
 * for one of them and "last week" for the other.
 */
function crmSummaryInstant(): Carbon
{
    return Carbon::parse('2026-08-16 23:30:00', 'UTC');
}

function companyCreatedAt(): Carbon
{
    return Carbon::parse('2026-08-14 12:00:00', 'UTC');
}

function crmSummaryWeekCount(User $user): int
{
    Auth::guard('web')->setUser($user);

    Company::factory()->create([
        'team_id' => $user->currentTeam->getKey(),
        'created_at' => companyCreatedAt(),
    ]);

    /** @var array{recent_activity: array{companies_this_week: int}} $decoded */
    $decoded = json_decode((new GetCrmSummaryTool)->handle(new Request([])), true);

    return $decoded['recent_activity']['companies_this_week'];
}

it('excludes a record from this week once the user calendar has rolled into a new week', function (): void {
    $this->travelTo(crmSummaryInstant());

    $tokyo = User::factory()->withPersonalTeam()->create(['timezone' => 'Asia/Tokyo']);

    expect(crmSummaryWeekCount($tokyo))->toBe(0);
});

it('counts that same record for a user still inside the previous week', function (): void {
    $this->travelTo(crmSummaryInstant());

    $utc = User::factory()->withPersonalTeam()->create(['timezone' => 'UTC']);

    expect(crmSummaryWeekCount($utc))->toBe(1);
});
