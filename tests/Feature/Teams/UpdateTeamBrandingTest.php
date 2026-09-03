<?php

declare(strict_types=1);

use App\Actions\Team\UpdateTeamBranding;
use App\Enums\TeamAccent;
use App\Livewire\App\Teams\UpdateTeamBranding as UpdateTeamBrandingComponent;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

mutates(UpdateTeamBranding::class, UpdateTeamBrandingComponent::class);

beforeEach(function (): void {
    Storage::fake('public');

    $this->user = User::factory()->create([
        'email' => 'branding@example.com',
        'email_verified_at' => now(),
    ]);

    $this->team = Team::factory()->create([
        'user_id' => $this->user->id,
        'personal_team' => false,
    ]);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

it('renders the branding section with the stored values', function (): void {
    $this->team->forceFill(['accent_color' => TeamAccent::Poseidon->value])->save();

    Livewire::test(UpdateTeamBrandingComponent::class, ['team' => $this->team])
        ->assertSuccessful()
        ->assertSee('Workspace Look')
        ->assertFormSet(['accent_color' => TeamAccent::Poseidon->value]);
});

it('saves a logo and accent color', function (): void {
    $logo = UploadedFile::fake()->image('logo.png', 200, 200);

    Livewire::test(UpdateTeamBrandingComponent::class, ['team' => $this->team])
        ->fillForm([
            'logo_path' => $logo,
            'accent_color' => TeamAccent::Sisyphus->value,
        ])
        ->call('updateBranding')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $team = $this->team->fresh();

    expect($team->accent_color)->toBe(TeamAccent::Sisyphus->value)
        ->and($team->logo_path)->not->toBeNull()
        ->and(Storage::disk('public')->exists($team->logo_path))->toBeTrue();
});

it('clears the accent back to the brand default', function (): void {
    $this->team->forceFill(['accent_color' => TeamAccent::Ares->value])->save();

    Livewire::test(UpdateTeamBrandingComponent::class, ['team' => $this->team])
        ->fillForm(['accent_color' => null])
        ->call('updateBranding')
        ->assertHasNoFormErrors();

    expect($this->team->fresh()->accent_color)->toBeNull();
});

it('replaces the workspace initials avatar with the logo url', function (): void {
    $this->team->forceFill(['logo_path' => 'team-logos/logo.png'])->save();

    expect($this->team->getFilamentAvatarUrl())->toEndWith('storage/team-logos/logo.png');
});

it('falls back to the generated initials avatar without a logo', function (): void {
    $this->team->forceFill(['logo_path' => null])->save();

    expect($this->team->getFilamentAvatarUrl())->toContain('data:image/svg+xml');
});
