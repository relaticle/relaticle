<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Relaticle\SystemAdmin\Filament\Pages\Auth\EditProfile;
use Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages\ListUsers;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(EditProfile::class);

/**
 * 2026-08-18 23:30 UTC is 2026-08-19 08:30 in Tokyo — deliberately across the date
 * line, so a test that only compares the clock time cannot pass by accident.
 */
function sysadminKnownInstant(): Carbon
{
    return Date::parse('2026-08-18 23:30:00', 'UTC');
}

function actAsAdminInZone(?string $timezone): SystemAdministrator
{
    $admin = SystemAdministrator::factory()->create(['timezone' => $timezone]);

    test()->actingAs($admin, 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    return $admin;
}

function seedUserAtKnownInstant(): void
{
    User::factory()->create([
        'name' => 'Timezone Fixture',
        'created_at' => sysadminKnownInstant(),
        'updated_at' => sysadminKnownInstant(),
    ]);
}

it('renders sysadmin datetimes in the administrator zone, labelled with it', function (): void {
    seedUserAtKnownInstant();
    actAsAdminInZone('Asia/Tokyo');

    livewire(ListUsers::class)
        ->assertSuccessful()
        ->assertSee('Aug 19, 2026 08:30:00 JST')
        ->assertDontSee('Aug 18, 2026 23:30:00 UTC');
});

it('keeps an administrator who has chosen no zone on utc', function (): void {
    seedUserAtKnownInstant();
    actAsAdminInZone(null);

    livewire(ListUsers::class)
        ->assertSuccessful()
        ->assertSee('Aug 18, 2026 23:30:00 UTC');
});

it('falls back to utc when the stored zone is no longer a valid identifier', function (): void {
    seedUserAtKnownInstant();
    // Written straight to the column: the profile form rejects this, but a zone that
    // PHP drops in a future tzdata release would arrive the same way.
    actAsAdminInZone(null)->forceFill(['timezone' => 'Not/ARealZone'])->save();

    livewire(ListUsers::class)
        ->assertSuccessful()
        ->assertSee('Aug 18, 2026 23:30:00 UTC');
});

it('lets an administrator choose a zone on the profile page', function (): void {
    $admin = actAsAdminInZone(null);

    livewire(EditProfile::class)
        ->fillForm(['timezone' => 'Europe/London'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($admin->refresh()->timezone)->toBe('Europe/London');
});

it('rejects a zone that is not a real identifier', function (): void {
    $admin = actAsAdminInZone(null);

    livewire(EditProfile::class)
        ->fillForm(['timezone' => 'Mars/Phobos'])
        ->call('save')
        ->assertHasFormErrors(['timezone']);

    expect($admin->refresh()->timezone)->toBeNull();
});
