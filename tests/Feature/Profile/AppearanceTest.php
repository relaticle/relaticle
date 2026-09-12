<?php

declare(strict_types=1);

use App\Enums\AccentColor;
use App\Filament\Pages\Appearance;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

mutates(Appearance::class, AccentColor::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
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
