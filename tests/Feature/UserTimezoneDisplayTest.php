<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Models\Note;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages\ListUsers;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

/**
 * 2026-08-18 23:30 UTC is 2026-08-19 08:30 in Tokyo — deliberately across the date
 * line, so a test that only compares the clock time cannot pass by accident.
 */
function knownInstant(): Carbon
{
    return Date::parse('2026-08-18 23:30:00', 'UTC');
}

function actAsTokyoUser(): User
{
    $user = User::factory()->withTeam()->create(['timezone' => 'Asia/Tokyo']);

    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($user->currentTeam);

    return $user;
}

it('resolves the app panel timezone to the signed-in user', function (): void {
    actAsTokyoUser();

    expect(FilamentTimezone::get())->toBe('Asia/Tokyo');
});

it('falls back to the app timezone for a user who has not set one', function (): void {
    $user = User::factory()->withTeam()->create(['timezone' => null]);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    expect(FilamentTimezone::get())->toBe(config('app.timezone'))
        ->and(FilamentTimezone::get())->toBe('UTC');
});

it('resolves each panel through its own guard, so a customer zone never leaks into sysadmin', function (): void {
    $user = User::factory()->withTeam()->create(['timezone' => 'Asia/Tokyo']);
    $this->actingAs($user);

    // The administrator has chosen no zone of their own, so the panel stays on UTC
    // and correlates with server logs — see SysadminTimezoneTest for the opt-in.
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    expect(FilamentTimezone::get())->toBe('UTC');
});

it('renders a stored utc datetime in the user timezone in the app panel', function (): void {
    $user = actAsTokyoUser();

    /** @var Team $team */
    $team = $user->currentTeam;

    Note::factory()->create([
        'team_id' => $team->getKey(),
        'creator_id' => $user->getKey(),
        'created_at' => knownInstant(),
        'updated_at' => knownInstant(),
    ]);

    livewire(ManageNotes::class)
        ->assertSuccessful()
        ->assertSee('2026-08-19 08:30:00')
        ->assertDontSee('2026-08-18 23:30:00');
});

it('labels sysadmin datetimes as utc so an admin never has to assume', function (): void {
    User::factory()->create([
        'name' => 'Timezone Fixture',
        'created_at' => knownInstant(),
        'updated_at' => knownInstant(),
    ]);

    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    livewire(ListUsers::class)
        ->assertSuccessful()
        ->assertSee('Aug 18, 2026 23:30:00 UTC');
});

it('does not ship the timezone detection script to signed-out visitors', function (): void {
    // The panel's own login page renders the same BODY_END hook, and the sync endpoint
    // behind it is authenticated — a script there could only ever produce a 401.
    $this->get(Filament::getPanel('app')->getLoginUrl())
        ->assertSuccessful()
        ->assertDontSee('resolvedOptions().timeZone', escape: false);
});

it('ships the timezone detection script to a signed-in user who has none yet', function (): void {
    $user = User::factory()->withPersonalTeam()->create(['timezone' => null]);
    $this->actingAs($user);
    Filament::setTenant($user->personalTeam());

    $this->get(Dashboard::getUrl(tenant: $user->personalTeam()))
        ->assertOk()
        ->assertSee('resolvedOptions().timeZone', escape: false);
});

it('stops shipping the detection script once the user has a timezone', function (): void {
    $user = User::factory()->withPersonalTeam()->create(['timezone' => 'Asia/Tokyo']);
    $this->actingAs($user);
    Filament::setTenant($user->personalTeam());

    $this->get(Dashboard::getUrl(tenant: $user->personalTeam()))
        ->assertOk()
        ->assertDontSee('resolvedOptions().timeZone', escape: false);
});
