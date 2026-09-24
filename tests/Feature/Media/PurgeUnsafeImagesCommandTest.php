<?php

declare(strict_types=1);

use App\Console\Commands\PurgeUnsafeImagesCommand;
use App\Jobs\FetchFaviconForCompany;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(PurgeUnsafeImagesCommand::class);

beforeEach(function (): void {
    Storage::fake('public');
    Queue::fake();

    $this->workspace = User::factory()->withPersonalWorkspace()->create()->personalWorkspace();

    $this->safeCompany = Company::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->safeLogo = $this->safeCompany->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);

    $this->unsafeCompany = Company::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->unsafeLogo = $this->unsafeCompany->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);
    Storage::disk('public')->delete($this->unsafeLogo->getPathRelativeToRoot());
    $this->unsafeLogo->forceFill(['file_name' => 'logo.svg', 'mime_type' => 'image/svg+xml'])->save();
    Storage::disk('public')->put($this->unsafeLogo->getPathRelativeToRoot(), '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    Storage::disk('public')->put('profile-photos/safe.png', onePixelPng());
    Storage::disk('public')->put('profile-photos/unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    $this->safeUser = User::factory()->create(['profile_photo_path' => 'profile-photos/safe.png']);
    $this->unsafeUser = User::factory()->create(['profile_photo_path' => 'profile-photos/unsafe.svg']);
});

it('reports unsafe images without touching them by default', function (): void {
    $this->artisan('media:purge-unsafe-images')
        ->expectsOutputToContain('2 unsafe image(s) would be removed')
        ->assertSuccessful();

    expect(Media::query()->whereKey($this->unsafeLogo->getKey())->exists())->toBeTrue()
        ->and($this->unsafeUser->fresh()->profile_photo_path)->toBe('profile-photos/unsafe.svg')
        ->and(Storage::disk('public')->exists('profile-photos/unsafe.svg'))->toBeTrue();

    Queue::assertNotPushed(FetchFaviconForCompany::class);
});

it('removes only the unsafe logos and profile photos with --force and refetches the logo', function (): void {
    $this->artisan('media:purge-unsafe-images --force')
        ->expectsOutputToContain('2 unsafe image(s) removed.')
        ->assertSuccessful();

    expect(Media::query()->whereKey($this->unsafeLogo->getKey())->exists())->toBeFalse()
        ->and(Media::query()->whereKey($this->safeLogo->getKey())->exists())->toBeTrue()
        ->and($this->unsafeUser->fresh()->profile_photo_path)->toBeNull()
        ->and(Storage::disk('public')->exists('profile-photos/unsafe.svg'))->toBeFalse()
        ->and($this->safeUser->fresh()->profile_photo_path)->toBe('profile-photos/safe.png')
        ->and(Storage::disk('public')->exists('profile-photos/safe.png'))->toBeTrue();

    Queue::assertPushed(FetchFaviconForCompany::class, fn (FetchFaviconForCompany $job): bool => $job->company->is($this->unsafeCompany));
    Queue::assertPushed(FetchFaviconForCompany::class, 1);
});

it('removes every unsafe logo when several are stored', function (): void {
    $secondCompany = Company::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $secondLogo = $secondCompany->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);
    $secondLogo->forceFill(['file_name' => 'logo.html', 'mime_type' => 'text/html'])->save();

    $this->artisan('media:purge-unsafe-images --force')
        ->expectsOutputToContain('3 unsafe image(s) removed.')
        ->assertSuccessful();

    expect(Media::query()->whereKey([$this->unsafeLogo->getKey(), $secondLogo->getKey()])->exists())->toBeFalse();

    Queue::assertPushed(FetchFaviconForCompany::class, 2);
});

it('keeps a profile photo whose type cannot be read', function (): void {
    Storage::disk('public')->put('profile-photos/unreadable', onePixelPng());
    chmod(Storage::disk('public')->path('profile-photos/unreadable'), 0000);
    $user = User::factory()->create(['profile_photo_path' => 'profile-photos/unreadable']);

    $this->artisan('media:purge-unsafe-images --force')->assertSuccessful();

    expect($user->fresh()->profile_photo_path)->toBe('profile-photos/unreadable')
        ->and(Storage::disk('public')->exists('profile-photos/unreadable'))->toBeTrue();
});
