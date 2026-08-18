<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Services\TenantContextService;
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
        ->assertSee('Aug 19, 2026 08:30')
        ->assertDontSee('Aug 18, 2026 23:30');
});

/**
 * Two separate things, both worth pinning. The panel declares one datetime format, so
 * a resource that renders a timestamp any other way is drift: that half reads the
 * format off the table rather than a literal, and keeps holding if the format changes.
 * Which format it declares is a product decision, so that half names it.
 */
it('renders every app panel datetime in the format the panel declares', function (): void {
    $user = actAsTokyoUser();

    /** @var Team $team */
    $team = $user->currentTeam;

    Note::factory()->create([
        'team_id' => $team->getKey(),
        'creator_id' => $user->getKey(),
        'created_at' => knownInstant(),
        'updated_at' => knownInstant(),
    ]);

    $format = Table::make(new ManageNotes)->getDefaultDateTimeDisplayFormat();

    expect($format)->toBe('M j, Y H:i');

    livewire(ManageNotes::class)
        ->assertSuccessful()
        ->assertSee(knownInstant()->setTimezone('Asia/Tokyo')->translatedFormat($format))
        ->assertDontSee('Aug 19, 2026 08:30:00');
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

/**
 * A record's view page renders custom fields through the package's infolist entry, which
 * hardcoded `Y-m-d H:i:s` when the package had no format configured — which it never does
 * here. So the page printed `2026-08-19 08:30:00` for the same field the table beside it
 * rendered as `Aug 19, 2026 08:30`. Same value, same row, two formats.
 */
it('renders a custom-field datetime on a record page in the panel format, not a raw literal', function (): void {
    $user = actAsTokyoUser();

    /** @var Team $team */
    $team = $user->currentTeam;

    TenantContextService::setTenantId($team->getKey());

    $field = CustomField::query()
        ->where('tenant_id', $team->getKey())
        ->where('entity_type', 'people')
        ->where('type', 'date-time')
        ->first();

    if (! $field instanceof CustomField) {
        $field = CustomField::forceCreate([
            'name' => 'Tz Meeting At',
            'code' => 'tz_meeting_at',
            'type' => 'date-time',
            'entity_type' => 'people',
            'tenant_id' => $team->getKey(),
            'sort_order' => 97,
            'active' => true,
            'system_defined' => false,
            'settings' => new CustomFieldSettingsData,
        ]);
    }

    $person = People::factory()->create([
        'team_id' => $team->getKey(),
        'creator_id' => $user->getKey(),
    ]);
    $person->saveCustomFieldValue($field, knownInstant()->toDateTimeString());

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->assertSuccessful()
        ->assertSee('Aug 19, 2026 08:30')
        ->assertDontSee('2026-08-19 08:30:00');
});
