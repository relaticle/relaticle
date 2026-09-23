<?php

declare(strict_types=1);

use App\Models\ActivityLog\Activity;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldSection;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;

mutates(CustomField::class, CustomFieldOption::class, Activity::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    $section = CustomFieldSection::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'company',
        'code' => 'general',
        'name' => 'General',
        'type' => 'section',
        'sort_order' => 0,
        'active' => true,
    ]);

    $this->field = CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_section_id' => $section->getKey(),
        'entity_type' => 'company',
        'code' => 'lead_source',
        'name' => 'Lead source',
        'type' => 'select',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    Activity::withoutGlobalScopes()->delete();
});

it('logs a rename against the field and its workspace, even outside a panel request', function (): void {
    Filament::setTenant(null);

    $this->field->update(['name' => 'Lead origin']);

    $activity = Activity::withoutGlobalScopes()->latest('id')->firstOrFail();

    expect($activity->subject_type)->toBe('custom_field')
        ->and($activity->workspace_id)->toBe($this->workspace->getKey())
        ->and($activity->attribute_changes['attributes']['name'])->toBe('Lead origin')
        ->and($activity->attribute_changes['old']['name'])->toBe('Lead source');
});

it('logs a settings change', function (): void {
    $settings = $this->field->settings;
    $settings->visible_in_list = false;

    $this->field->update(['settings' => $settings]);

    expect(Activity::withoutGlobalScopes()->latest('id')->firstOrFail()->attribute_changes['attributes'])
        ->toHaveKey('settings');
});

it('logs a deactivation', function (): void {
    $this->field->update(['active' => false]);

    expect(Activity::withoutGlobalScopes()->latest('id')->firstOrFail()->attribute_changes['attributes']['active'])
        ->toBeFalse();
});

it('does not log a pure reorder', function (): void {
    $this->field->update(['sort_order' => 7]);

    expect(Activity::withoutGlobalScopes()->count())->toBe(0);
});

it('logs a new option against the option itself, even outside a panel request', function (): void {
    Filament::setTenant(null);

    $this->field->options()->create([
        'tenant_id' => $this->workspace->getKey(),
        'name' => 'Referral',
        'sort_order' => 1,
    ]);

    $activity = Activity::withoutGlobalScopes()->latest('id')->firstOrFail();

    expect($activity->subject_type)->toBe('custom_field_option')
        ->and($activity->workspace_id)->toBe($this->workspace->getKey())
        ->and($activity->attribute_changes['attributes']['name'])->toBe('Referral');
});

it('records the settings before and after the change', function (): void {
    $this->field->update(['settings' => new CustomFieldSettingsData(visible_in_list: false)]);

    $activity = Activity::withoutGlobalScopes()->latest('id')->firstOrFail();

    expect($activity->attribute_changes['old']['settings']['visible_in_list'])->toBeTrue()
        ->and($activity->attribute_changes['attributes']['settings']['visible_in_list'])->toBeFalse();
});

it('never writes the plaintext name of an option on an encrypted field', function (): void {
    $this->field->update(['settings' => new CustomFieldSettingsData(encrypted: true)]);

    $option = $this->field->options()->create([
        'tenant_id' => $this->workspace->getKey(),
        'name' => 'Confidential source',
        'sort_order' => 1,
    ]);

    $option->update(['name' => 'Secret referral']);

    $logged = Activity::withoutGlobalScopes()
        ->where('subject_type', 'custom_field_option')
        ->get()
        ->map(fn (Activity $activity): string => json_encode($activity->attribute_changes))
        ->implode(' ');

    expect($logged)->not->toBeEmpty()
        ->not->toContain('Confidential source')
        ->not->toContain('Secret referral');
});
