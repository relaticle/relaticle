<?php

declare(strict_types=1);

use App\Enums\AccentColor;
use App\Filament\Pages\AccessTokens;
use App\Filament\Pages\Appearance;
use App\Filament\Pages\NotificationPreferences;
use App\Filament\Pages\Security;
use App\Models\User;
use App\Support\Locales;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Relaticle\Chat\Filament\Pages\AssistantSettings;

mutates(Appearance::class, AccentColor::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);
});

it('renders every theme mode and accent swatch', function (): void {
    $page = Livewire::test(Appearance::class)
        ->assertSuccessful()
        ->assertSee('Light')
        ->assertSee('Dark')
        ->assertSee('System');

    foreach (AccentColor::cases() as $accent) {
        $page->assertSee($accent->label());
    }
});

it('renders accent labels through the translation layer', function (): void {
    app('translator')->addLines(['appearance.accent_colors.Blue' => 'Azure'], 'en');

    Livewire::test(Appearance::class)
        ->assertSuccessful()
        ->assertSee('Azure');
});

it('ships a primary ramp for every accent', function (): void {
    $this->get(Appearance::getUrl())
        ->assertSuccessful()
        ->assertSee('html[data-accent="'.AccentColor::Blue->name.'"]', false)
        ->assertSee('--primary-500', false);
});

it('restores the stored accent on every page load', function (): void {
    $this->get(Appearance::getUrl())
        ->assertSuccessful()
        ->assertSee('const loadAccent', false)
        ->assertSee("document.addEventListener('livewire:navigated', loadAccent)", false);
});

it('titles every settings page in the user language', function (string $page, string $title): void {
    Locales::apply('de');

    expect(Livewire::test($page)->instance()->getTitle())->toBe($title);
})->with([
    'appearance' => [Appearance::class, 'Erscheinungsbild'],
    'security' => [Security::class, 'Sicherheit'],
    'access tokens' => [AccessTokens::class, 'Zugriffstoken'],
    'notification preferences' => [NotificationPreferences::class, 'Benachrichtigungseinstellungen'],
    'assistant settings' => [AssistantSettings::class, 'Assistenteneinstellungen'],
]);
