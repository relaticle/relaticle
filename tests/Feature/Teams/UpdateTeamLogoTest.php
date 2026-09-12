<?php

declare(strict_types=1);

use App\Filament\Pages\EditTeam;
use App\Livewire\App\Teams\UpdateTeamLogo;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

mutates(UpdateTeamLogo::class);

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

it('renders the workspace logo section', function (): void {
    Livewire::test(UpdateTeamLogo::class, ['team' => $this->team])
        ->assertSuccessful()
        ->assertSee('Workspace Logo');
});

it('stores an uploaded logo in the media collection', function (): void {
    Livewire::test(UpdateTeamLogo::class, ['team' => $this->team])
        ->fillForm(['logo' => UploadedFile::fake()->image('logo.png', 200, 200)])
        ->call('updateLogo')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($this->team->fresh()->getFirstMedia(Team::LOGO_MEDIA_COLLECTION))->not->toBeNull();
});

it('keeps only the latest logo when a new one replaces it', function (): void {
    $component = Livewire::test(UpdateTeamLogo::class, ['team' => $this->team])
        ->fillForm(['logo' => UploadedFile::fake()->image('first.png', 200, 200)])
        ->call('updateLogo');

    $component
        ->fillForm(['logo' => UploadedFile::fake()->image('second.png', 200, 200)])
        ->call('updateLogo')
        ->assertHasNoFormErrors();

    expect($this->team->fresh()->getMedia(Team::LOGO_MEDIA_COLLECTION))->toHaveCount(1);
});

it('clears the logo when the upload is emptied', function (): void {
    $component = Livewire::test(UpdateTeamLogo::class, ['team' => $this->team])
        ->fillForm(['logo' => UploadedFile::fake()->image('logo.png', 200, 200)])
        ->call('updateLogo');

    expect($this->team->fresh()->getMedia(Team::LOGO_MEDIA_COLLECTION))->toHaveCount(1);

    $component
        ->fillForm(['logo' => []])
        ->call('updateLogo')
        ->assertHasNoFormErrors();

    $team = $this->team->fresh();

    expect($team->getMedia(Team::LOGO_MEDIA_COLLECTION))->toHaveCount(0)
        ->and($team->getFilamentAvatarUrl())->toContain('data:image/svg+xml');
});

it('holds a single logo even when media is added outside the form', function (): void {
    $this->team->addMedia(UploadedFile::fake()->image('first.png', 120, 120))
        ->toMediaCollection(Team::LOGO_MEDIA_COLLECTION);

    $this->team->addMedia(UploadedFile::fake()->image('second.png', 120, 120))
        ->toMediaCollection(Team::LOGO_MEDIA_COLLECTION);

    expect($this->team->fresh()->getMedia(Team::LOGO_MEDIA_COLLECTION))->toHaveCount(1);
});

it('replaces the workspace initials avatar with the logo url', function (): void {
    $this->team
        ->addMedia(UploadedFile::fake()->image('logo.png', 200, 200))
        ->toMediaCollection(Team::LOGO_MEDIA_COLLECTION);

    expect($this->team->fresh()->getFilamentAvatarUrl())->toContain('logo.png');
});

it('falls back to the generated initials avatar without a logo', function (): void {
    expect($this->team->getFilamentAvatarUrl())->toContain('data:image/svg+xml');
});

it('refuses a logo change from a member who does not own the workspace', function (): void {
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => 'editor']);

    $this->actingAs($member);

    Livewire::test(UpdateTeamLogo::class, ['team' => $this->team])
        ->fillForm(['logo' => UploadedFile::fake()->image('sneaky.png', 120, 120)])
        ->call('updateLogo')
        ->assertForbidden();

    expect($this->team->fresh()->getMedia(Team::LOGO_MEDIA_COLLECTION))->toHaveCount(0);
});

it('offers the logo section on a personal workspace', function (): void {
    $this->team->forceFill(['personal_team' => true])->save();

    Livewire::test(EditTeam::class)
        ->assertSuccessful()
        ->assertSeeLivewire(UpdateTeamLogo::class);
});
