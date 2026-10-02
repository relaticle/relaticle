<?php

declare(strict_types=1);

use App\Models\CustomField;
use App\Models\User;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;

$migration = fn (): mixed => (require base_path('database/migrations/2026_10_03_000000_set_domain_link_variant_on_company_domains.php'))->up();

$companyField = fn (string $code): CustomField => CustomField::query()
    ->withoutGlobalScopes()
    ->where('tenant_id', test()->workspace->id)
    ->where('entity_type', 'company')
    ->where('code', $code)
    ->firstOrFail();

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
});

it('marks company domains as the domain link variant and keeps their other settings', function () use ($migration, $companyField): void {
    $domains = $companyField('domains');
    $domains->settings = new CustomFieldSettingsData(allow_multiple: true, max_values: 5, additional: ['placeholder' => 'acme.com']);
    $domains->save();

    $migration();

    $migrated = $companyField('domains');

    expect($migrated->setting('link_variant'))->toBe('domain')
        ->and($migrated->setting('placeholder'))->toBe('acme.com')
        ->and($migrated->settings->allow_multiple)->toBeTrue()
        ->and($migrated->settings->max_values)->toBe(5);
});

it('leaves other link fields untouched', function () use ($migration, $companyField): void {
    $migration();

    expect($companyField('linkedin')->setting('link_variant'))->toBeNull();
});
