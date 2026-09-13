<?php

declare(strict_types=1);

use App\Filament\Pages\EditWorkspace;
use App\Livewire\App\Workspaces\UpdateWorkspaceLogo;
use App\Models\User;
use App\Models\Workspace;
use App\Support\SameOriginUrlGenerator;
use Filament\Facades\Filament;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

mutates(UpdateWorkspaceLogo::class, SameOriginUrlGenerator::class);

beforeEach(function (): void {
    Storage::fake('public');

    $this->user = User::factory()->create([
        'email' => 'branding@example.com',
        'email_verified_at' => now(),
    ]);

    $this->workspace = Workspace::factory()->create([
        'user_id' => $this->user->id,
        'personal_workspace' => false,
    ]);

    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
});

it('renders the workspace logo section', function (): void {
    Livewire::test(UpdateWorkspaceLogo::class, ['workspace' => $this->workspace])
        ->assertSuccessful()
        ->assertSee('Workspace Logo');
});

it('stores an uploaded logo in the media collection', function (): void {
    Livewire::test(UpdateWorkspaceLogo::class, ['workspace' => $this->workspace])
        ->fillForm(['logo' => UploadedFile::fake()->image('logo.png', 200, 200)])
        ->call('updateLogo')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($this->workspace->fresh()->getFirstMedia(Workspace::LOGO_MEDIA_COLLECTION))->not->toBeNull();
});

it('keeps only the latest logo when a new one replaces it', function (): void {
    $component = Livewire::test(UpdateWorkspaceLogo::class, ['workspace' => $this->workspace])
        ->fillForm(['logo' => UploadedFile::fake()->image('first.png', 200, 200)])
        ->call('updateLogo');

    $component
        ->fillForm(['logo' => UploadedFile::fake()->image('second.png', 200, 200)])
        ->call('updateLogo')
        ->assertHasNoFormErrors();

    expect($this->workspace->fresh()->getMedia(Workspace::LOGO_MEDIA_COLLECTION))->toHaveCount(1);
});

it('clears the logo when the upload is emptied', function (): void {
    $component = Livewire::test(UpdateWorkspaceLogo::class, ['workspace' => $this->workspace])
        ->fillForm(['logo' => UploadedFile::fake()->image('logo.png', 200, 200)])
        ->call('updateLogo');

    expect($this->workspace->fresh()->getMedia(Workspace::LOGO_MEDIA_COLLECTION))->toHaveCount(1);

    $component
        ->fillForm(['logo' => []])
        ->call('updateLogo')
        ->assertHasNoFormErrors();

    $workspace = $this->workspace->fresh();

    expect($workspace->getMedia(Workspace::LOGO_MEDIA_COLLECTION))->toHaveCount(0)
        ->and($workspace->getFilamentAvatarUrl())->toContain('data:image/svg+xml');
});

it('rejects an image type outside the logo allowlist', function (): void {
    Livewire::test(UpdateWorkspaceLogo::class, ['workspace' => $this->workspace])
        ->fillForm(['logo' => UploadedFile::fake()->image('logo.gif', 120, 120)])
        ->call('updateLogo')
        ->assertHasFormErrors(['logo']);

    expect($this->workspace->fresh()->getMedia(Workspace::LOGO_MEDIA_COLLECTION))->toHaveCount(0);
});

it('keeps the existing logo when the form is saved untouched', function (): void {
    $this->workspace->addMedia(UploadedFile::fake()->image('existing.png', 120, 120))
        ->toMediaCollection(Workspace::LOGO_MEDIA_COLLECTION);

    Livewire::test(UpdateWorkspaceLogo::class, ['workspace' => $this->workspace])
        ->call('updateLogo')
        ->assertHasNoFormErrors();

    expect($this->workspace->fresh()->getMedia(Workspace::LOGO_MEDIA_COLLECTION))->toHaveCount(1);
});

it('rejects a logo larger than the size cap', function (): void {
    Livewire::test(UpdateWorkspaceLogo::class, ['workspace' => $this->workspace])
        ->fillForm(['logo' => UploadedFile::fake()->image('huge.png', 4000, 4000)->size(Workspace::LOGO_MAX_KILOBYTES + 1)])
        ->call('updateLogo')
        ->assertHasFormErrors(['logo']);

    expect($this->workspace->fresh()->getMedia(Workspace::LOGO_MEDIA_COLLECTION))->toHaveCount(0);
});

it('holds a single logo even when media is added outside the form', function (): void {
    $this->workspace->addMedia(UploadedFile::fake()->image('first.png', 120, 120))
        ->toMediaCollection(Workspace::LOGO_MEDIA_COLLECTION);

    $this->workspace->addMedia(UploadedFile::fake()->image('second.png', 120, 120))
        ->toMediaCollection(Workspace::LOGO_MEDIA_COLLECTION);

    expect($this->workspace->fresh()->getMedia(Workspace::LOGO_MEDIA_COLLECTION))->toHaveCount(1);
});

it('replaces the workspace initials avatar with the logo url', function (): void {
    $this->workspace
        ->addMedia(UploadedFile::fake()->image('logo.png', 200, 200))
        ->toMediaCollection(Workspace::LOGO_MEDIA_COLLECTION);

    expect($this->workspace->fresh()->getFilamentAvatarUrl())->toContain('logo.png');
});

it('falls back to the generated initials avatar without a logo', function (): void {
    expect($this->workspace->getFilamentAvatarUrl())->toContain('data:image/svg+xml');
});

it('previews a stored logo from the requesting host so the panel subdomain can fetch it', function (): void {
    config(['app.url' => 'https://relaticle.test']);
    Storage::fake('public', ['url' => 'https://relaticle.test/storage']);

    $this->workspace
        ->addMedia(UploadedFile::fake()->image('logo.png', 120, 120))
        ->toMediaCollection(Workspace::LOGO_MEDIA_COLLECTION);

    app()->instance('request', Request::create('https://app.relaticle.test/'));

    $logo = Livewire::test(UpdateWorkspaceLogo::class, ['workspace' => $this->workspace])
        ->instance()
        ->getSchema('form')
        ->getComponent('logo');

    expect($logo)->toBeInstanceOf(SpatieMediaLibraryFileUpload::class);

    /** @var SpatieMediaLibraryFileUpload $logo */
    $urls = array_column($logo->getUploadedFiles() ?? [], 'url');

    expect($urls)->toHaveCount(1)
        ->and($urls[0])->toStartWith('https://app.relaticle.test/storage/');
});

it('refuses a logo change from a member who does not own the workspace', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'editor']);

    $this->actingAs($member);

    Livewire::test(UpdateWorkspaceLogo::class, ['workspace' => $this->workspace])
        ->fillForm(['logo' => UploadedFile::fake()->image('sneaky.png', 120, 120)])
        ->call('updateLogo')
        ->assertForbidden();

    expect($this->workspace->fresh()->getMedia(Workspace::LOGO_MEDIA_COLLECTION))->toHaveCount(0);
});

it('offers the logo section on a personal workspace', function (): void {
    $this->workspace->forceFill(['personal_workspace' => true])->save();

    Livewire::test(EditWorkspace::class)
        ->assertSuccessful()
        ->assertSeeLivewire(UpdateWorkspaceLogo::class);
});
