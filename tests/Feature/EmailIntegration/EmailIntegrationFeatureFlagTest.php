<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Filament\Resources\PeopleResource\RelationManagers\MeetingsRelationManager;
use App\Models\People;
use App\Models\User;
use App\Policies\EmailPolicy;
use App\Policies\MeetingPolicy;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Meeting;

mutates(EmailIntegration::class, EmailPolicy::class, MeetingPolicy::class);

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

it('forbids listing emails when the feature is inactive', function (): void {
    Feature::deactivate(EmailIntegration::class);

    expect($this->user->can('viewAny', Email::class))->toBeFalse();
});

it('forbids listing meetings when the feature is inactive', function (): void {
    Feature::deactivate(EmailIntegration::class);

    expect($this->user->can('viewAny', Meeting::class))->toBeFalse();
});

it('hides emails and meetings relation managers on a person when the feature is inactive', function (): void {
    Feature::deactivate(EmailIntegration::class);

    $person = People::factory()->recycle([$this->user, $this->user->currentTeam])->create();

    expect(EmailsRelationManager::canViewForRecord($person, ViewPeople::class))->toBeFalse()
        ->and(MeetingsRelationManager::canViewForRecord($person, ViewPeople::class))->toBeFalse();

    $managers = livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->instance()
        ->getRelationManagers();

    expect($managers)->not->toContain(EmailsRelationManager::class)
        ->and($managers)->not->toContain(MeetingsRelationManager::class);
});

it('registers emails and meetings relation managers on a person when the feature is active', function (): void {
    Feature::activate(EmailIntegration::class);

    $person = People::factory()->recycle([$this->user, $this->user->currentTeam])->create();

    $managers = livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->instance()
        ->getRelationManagers();

    expect($managers)->toContain(EmailsRelationManager::class)
        ->and($managers)->toContain(MeetingsRelationManager::class);
});

it('forbids listing emails when the viewer has no verified email', function (): void {
    Feature::activate(EmailIntegration::class);

    $unverified = User::factory()->unverified()->withTeam()->create();

    $this->actingAs($unverified);

    expect($unverified->can('viewAny', Email::class))->toBeFalse();
});

it('forbids listing meetings when the viewer has no verified email', function (): void {
    Feature::activate(EmailIntegration::class);

    $unverified = User::factory()->unverified()->withTeam()->create();

    $this->actingAs($unverified);

    expect($unverified->can('viewAny', Meeting::class))->toBeFalse();
});
