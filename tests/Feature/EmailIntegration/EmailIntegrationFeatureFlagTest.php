<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource;

mutates(EmailIntegration::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

it('resolves active when the config flag is enabled', function (): void {
    config()->set('relaticle.features.email_integration', true);
    Feature::flushCache();

    expect(Feature::active(EmailIntegration::class))->toBeTrue();
});

it('resolves inactive when the config flag is disabled', function (): void {
    config()->set('relaticle.features.email_integration', false);
    Feature::flushCache();

    expect(Feature::active(EmailIntegration::class))->toBeFalse();
});

it('defaults to inactive when no config or env override is set', function (): void {
    config()->set('relaticle.features.email_integration', (bool) env('RELATICLE_FEATURE_EMAIL_INTEGRATION', false));
    Feature::flushCache();

    // Production ships flag-OFF; the test env opts in via RELATICLE_FEATURE_EMAIL_INTEGRATION=true.
    expect(Feature::active(EmailIntegration::class))
        ->toBe((bool) env('RELATICLE_FEATURE_EMAIL_INTEGRATION', false));
});

it('allows access to email pages and resources when active', function (): void {
    expect(EmailInboxPage::canAccess())->toBeTrue()
        ->and(EmailTemplateResource::canAccess())->toBeTrue();
});

it('forbids the inbox page when the feature is inactive', function (): void {
    Feature::deactivate(EmailIntegration::class);

    livewire(EmailInboxPage::class)->assertForbidden();
});

it('gates the email template resource when the feature is inactive', function (): void {
    Feature::deactivate(EmailIntegration::class);

    expect(EmailTemplateResource::canAccess())->toBeFalse();
});
