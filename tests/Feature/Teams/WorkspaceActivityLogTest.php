<?php

declare(strict_types=1);

use App\Filament\Pages\Team\ActivityLog;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;

mutates(ActivityLog::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create(['name' => 'Ada Owner']);
    $this->actingAs($this->owner);
    $this->team = $this->owner->currentTeam;
    Filament::setTenant($this->team);

    Activity::withoutGlobalScopes()->delete();
});

test('an admin sees who deleted a record and when', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Lumen Robotics']);
    $company->delete();

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertSee('Ada Owner')
        ->assertSee('Lumen Robotics')
        ->assertSee(__('teams.activity.events.deleted'));
});

test('the audit row survives a permanent delete', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Vanished Corp']);
    $company->delete();
    $company->forceDelete();

    expect(Company::withTrashed()->find($company->getKey()))->toBeNull();

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertSee('Vanished Corp')
        ->assertSee('Ada Owner');
});

test('a record that still exists links back to it, a destroyed one does not', function (): void {
    $alive = Company::factory()->for($this->team)->create(['name' => 'Alive Corp']);
    $alive->delete();

    $destroyed = Company::factory()->for($this->team)->create(['name' => 'Vanished Corp']);
    $destroyedId = $destroyed->getKey();
    $destroyed->delete();
    $destroyed->forceDelete();

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertSee("companies/{$alive->getKey()}")
        ->assertDontSee("companies/{$destroyedId}");
});

test('it lists deletions across every crm record type', function (string $factory, string $name): void {
    $record = $factory::factory()->for($this->team)->create(['name' => $name]);
    $record->delete();

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertSee($name);
})->with([
    'company' => [Company::class, 'Deleted Company'],
    'people' => [People::class, 'Deleted Person'],
    'opportunity' => [Opportunity::class, 'Deleted Opportunity'],
]);

test('another workspace activity never leaks in', function (): void {
    $stranger = User::factory()->withTeam()->create(['name' => 'Mallory Stranger']);
    $strangerTeam = $stranger->currentTeam;

    $this->actingAs($stranger);
    Filament::setTenant($strangerTeam);
    Company::factory()->for($strangerTeam)->create(['name' => 'Foreign Holdings'])->delete();

    $this->actingAs($this->owner);
    Filament::setTenant($this->team);

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertDontSee('Foreign Holdings')
        ->assertDontSee('Mallory Stranger');
});

test('a member without the admin role cannot open the audit log', function (): void {
    $editor = User::factory()->create(['name' => 'Eddie Editor']);
    $this->team->users()->attach($editor, ['role' => 'editor']);

    $this->actingAs($editor);
    Filament::setTenant($this->team);

    expect(ActivityLog::canAccess())->toBeFalse();

    $this->get(ActivityLog::getUrl(tenant: $this->team))->assertForbidden();
});

test('an admin can open the audit log over http', function (): void {
    $this->get(ActivityLog::getUrl(tenant: $this->team))->assertSuccessful();
});

test('a custom field edit reads as an update, not its raw event name', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Field Edited Co']);

    activity()
        ->performedOn($company)
        ->causedBy($this->owner)
        ->event('custom_field_changes')
        ->log('custom field changed');

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertSee(__('teams.activity.events.updated'))
        ->assertDontSee('custom_field_changes');
});

test('filtering on updated covers custom field edits too', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Field Edited Co']);
    Company::factory()->for($this->team)->create(['name' => 'Untouched Co'])->delete();

    activity()
        ->performedOn($company)
        ->causedBy($this->owner)
        ->event('custom_field_changes')
        ->log('custom field changed');

    livewire(ActivityLog::class)
        ->filterTable('event', 'updated')
        ->assertSee('Field Edited Co')
        ->assertDontSee('Untouched Co');
});

test('it filters down to deletions only', function (): void {
    Company::factory()->for($this->team)->create(['name' => 'Still Here']);
    Company::factory()->for($this->team)->create(['name' => 'Gone Already'])->delete();

    livewire(ActivityLog::class)
        ->filterTable('event', 'deleted')
        ->assertSee('Gone Already')
        ->assertDontSee('Still Here');
});
