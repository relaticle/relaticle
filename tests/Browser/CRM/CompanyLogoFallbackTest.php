<?php

declare(strict_types=1);

use App\Enums\CustomFields\CompanyField;
use App\Filament\Components\RecordChip;
use App\Models\Company;
use App\Models\User;

mutates(RecordChip::class);

it('shows the company icon when the brand logo fails to load', function (): void {
    config()->set('services.brandfetch.client_id', 'test-client');

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    Company::factory()->recycle([$user, $workspace])->create([
        'name' => 'Acme Corp',
        'custom_fields' => [CompanyField::DOMAINS->value => ['acme-logo-fallback.invalid']],
    ]);

    $page = visitAs($user, "/app/{$workspace->slug}/companies")->assertSee('Acme Corp');

    $state = $page->wait(2)->script(<<<'JS'
        (() => {
            const chip = [...document.querySelectorAll('.fi-record-chip')].find((el) => el.textContent.includes('Acme Corp'));
            const image = chip.querySelector('img');
            const icon = chip.querySelector('svg');

            return {
                hasLogoUrl: image.src.startsWith('https://cdn.brandfetch.io/domain/acme-logo-fallback.invalid/'),
                imageShown: getComputedStyle(image).display !== 'none',
                iconShown: getComputedStyle(icon.parentElement).display !== 'none',
            };
        })();
    JS);

    expect($state)->toBe(['hasLogoUrl' => true, 'imageShown' => false, 'iconShown' => true]);
});
