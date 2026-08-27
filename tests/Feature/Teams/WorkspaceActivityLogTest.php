<?php

declare(strict_types=1);

use App\Filament\Pages\Team\ActivityLog;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

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

test('one save is one row, even when custom fields log their own event', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Batched Co']);

    $batch = (string) Str::uuid();

    $native = Activity::withoutGlobalScopes()->create([
        'log_name' => 'crm',
        'description' => 'created',
        'event' => 'created',
        'subject_type' => $company->getMorphClass(),
        'subject_id' => $company->getKey(),
        'causer_type' => 'user',
        'causer_id' => $this->owner->getKey(),
        'team_id' => $this->team->getKey(),
        'batch_uuid' => $batch,
        'attribute_changes' => ['attributes' => ['name' => 'Batched Co']],
    ]);

    $sibling = Activity::withoutGlobalScopes()->create([
        'log_name' => 'crm',
        'description' => 'custom_field_changes',
        'event' => 'custom_field_changes',
        'subject_type' => $company->getMorphClass(),
        'subject_id' => $company->getKey(),
        'causer_type' => 'user',
        'causer_id' => $this->owner->getKey(),
        'team_id' => $this->team->getKey(),
        'batch_uuid' => $batch,
        'properties' => ['custom_field_changes' => [['label' => 'ICP', 'old' => null, 'new' => 'Yes']]],
    ]);

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$native])
        ->assertCanNotSeeTableRecords([$sibling])
        ->assertSee('ICP: — → Yes', escape: false);
});

test('one batch spanning two records keeps each record its own row and payload', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Batch Company']);
    $person = People::factory()->for($this->team)->create(['name' => 'Batch Person']);

    $batch = (string) Str::uuid();

    $rows = collect([$company, $person])->map(fn ($record, $index) => Activity::withoutGlobalScopes()->create([
        'log_name' => 'crm',
        'description' => 'updated',
        'event' => 'updated',
        'subject_type' => $record->getMorphClass(),
        'subject_id' => $record->getKey(),
        'causer_type' => 'user',
        'causer_id' => $this->owner->getKey(),
        'team_id' => $this->team->getKey(),
        'batch_uuid' => $batch,
        'attribute_changes' => ['attributes' => ['name' => $record->name], 'old' => ['name' => 'Was '.$index]],
    ]));

    Activity::withoutGlobalScopes()->create([
        'log_name' => 'crm',
        'description' => 'custom_field_changes',
        'event' => 'custom_field_changes',
        'subject_type' => $company->getMorphClass(),
        'subject_id' => $company->getKey(),
        'causer_type' => 'user',
        'causer_id' => $this->owner->getKey(),
        'team_id' => $this->team->getKey(),
        'batch_uuid' => $batch,
        'properties' => ['custom_field_changes' => [['label' => 'Company Only Field', 'old' => null, 'new' => 'Set']]],
    ]);

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertCanSeeTableRecords($rows->all())
        ->assertSee('Batch Company')
        ->assertSee('Batch Person')
        ->assertSee('Company Only Field: — → Set', escape: false);
});

test('a create and a delete in one request both stay on the record', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Short Lived Co']);
    $batch = (string) Str::uuid();

    $rows = collect(['created', 'deleted'])->map(fn (string $event) => Activity::withoutGlobalScopes()->create([
        'log_name' => 'crm',
        'description' => $event,
        'event' => $event,
        'subject_type' => $company->getMorphClass(),
        'subject_id' => $company->getKey(),
        'causer_type' => 'user',
        'causer_id' => $this->owner->getKey(),
        'team_id' => $this->team->getKey(),
        'batch_uuid' => $batch,
        'attribute_changes' => ['attributes' => ['name' => 'Short Lived Co']],
    ]));

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertCanSeeTableRecords($rows->all())
        ->assertSee(__('teams.activity.events.created'))
        ->assertSee(__('teams.activity.events.deleted'));
});

/**
 * Each custom field that moves is logged as its own row, so a save touching
 * three fields writes three siblings — not one.
 */
function seedCustomFieldRow(object $test, Company $company, string $batch, string $label, string $old, string $new): Activity
{
    return Activity::withoutGlobalScopes()->create([
        'log_name' => 'crm',
        'description' => 'custom_field_changes',
        'event' => 'custom_field_changes',
        'subject_type' => $company->getMorphClass(),
        'subject_id' => $company->getKey(),
        'causer_type' => 'user',
        'causer_id' => $test->owner->getKey(),
        'team_id' => $test->team->getKey(),
        'batch_uuid' => $batch,
        'properties' => ['custom_field_changes' => [['label' => $label, 'old' => $old, 'new' => $new]]],
    ]);
}

test('a save touching several custom fields shows every one of them', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Many Fields Co']);
    $batch = (string) Str::uuid();

    Activity::withoutGlobalScopes()->create([
        'log_name' => 'crm',
        'description' => 'created',
        'event' => 'created',
        'subject_type' => $company->getMorphClass(),
        'subject_id' => $company->getKey(),
        'causer_type' => 'user',
        'causer_id' => $this->owner->getKey(),
        'team_id' => $this->team->getKey(),
        'batch_uuid' => $batch,
        'attribute_changes' => ['attributes' => ['name' => 'Many Fields Co']],
    ]);

    seedCustomFieldRow($this, $company, $batch, 'Status', 'None', 'To do');
    seedCustomFieldRow($this, $company, $batch, 'Priority', 'None', 'High');
    seedCustomFieldRow($this, $company, $batch, 'Due Date', 'None', '2026-08-08');

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertSee('Status: None → To do', escape: false)
        ->assertSee('Priority: None → High', escape: false)
        ->assertSee('Due Date: None → 2026-08-08', escape: false);
});

test('custom field rows with no native sibling collapse without borrowing each others diffs', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Field Only Co']);
    $batch = (string) Str::uuid();

    $first = seedCustomFieldRow($this, $company, $batch, 'Priority', 'Medium', 'High');
    $second = seedCustomFieldRow($this, $company, $batch, 'Description', 'Old copy', 'New copy');
    $third = seedCustomFieldRow($this, $company, $batch, 'Due Date', '2026-07-20', '2026-08-20');

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$first])
        ->assertCanNotSeeTableRecords([$second, $third])
        ->assertSee('Priority: Medium → High', escape: false)
        ->assertSee('Description: Old copy → New copy', escape: false)
        ->assertSee('Due Date: 2026-07-20 → 2026-08-20', escape: false);
});

test('a stale morph alias whose model is gone does not take the page down', function (): void {
    $orphan = Activity::withoutGlobalScopes()->create([
        'log_name' => 'crm',
        'description' => 'meeting.created',
        'event' => 'meeting.created',
        'subject_type' => 'meeting',
        'subject_id' => '01kxn0qpzsz9hb0tseg3xy01mm',
        'causer_type' => 'user',
        'causer_id' => $this->owner->getKey(),
        'team_id' => $this->team->getKey(),
        'properties' => ['title' => 'Quarterly review call'],
    ]);

    Company::factory()->for($this->team)->create(['name' => 'Neighbour Co'])->delete();

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$orphan])
        ->assertSee('Neighbour Co')
        ->sortTable('created_at')
        ->assertOk();
});

test('renaming a record does not rewrite its history', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Original Name Ltd']);
    $company->update(['name' => 'Renamed Name Ltd']);

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertSee('Original Name Ltd')
        ->assertSee('Renamed Name Ltd');
});

test('it spells out what actually changed on a row', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Diff Co']);
    $company->update(['name' => 'Diff Co Renamed']);

    livewire(ActivityLog::class)
        ->assertOk()
        ->assertSee('Diff Co → Diff Co Renamed', escape: false);
});

test('filter and sort state is bound to the url so a view can be shared', function (): void {
    $page = new ReflectionClass(ActivityLog::class);

    $bound = [];

    foreach (['tableFilters', 'tableSort'] as $property) {
        $attributes = $page->getProperty($property)->getAttributes(Url::class);
        $bound[$property] = $attributes !== [];
    }

    expect($bound)->toBe([
        'tableFilters' => true,
        'tableSort' => true,
    ]);
});

test('a shared filtered url renders already filtered', function (): void {
    Company::factory()->for($this->team)->create(['name' => 'Still Here']);
    Company::factory()->for($this->team)->create(['name' => 'Gone Already'])->delete();

    livewire(ActivityLog::class, ['tableFilters' => ['event' => ['value' => 'deleted']]])
        ->assertOk()
        ->assertSee('Gone Already')
        ->assertDontSee('Still Here');
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
