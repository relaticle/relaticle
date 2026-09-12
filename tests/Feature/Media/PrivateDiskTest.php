<?php

declare(strict_types=1);

use App\Enums\MediaCollection;
use App\Http\Controllers\Media\ShowMediaController;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\UploadFileTool;
use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use App\Support\Media\MediaUrlGenerator;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(MediaUrlGenerator::class, ShowMediaController::class, Team::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

function usePrivateMediaDisk(): void
{
    config()->set('filesystems.disks.media', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/media'),
        'visibility' => 'private',
        'throw' => false,
    ]);
    config()->set('media-library.disk_name', 'media');
    Storage::fake('media', ['visibility' => 'private']);
}

function uploadPdf(User $user): Media
{
    RelaticleServer::actingAs($user)
        ->tool(UploadFileTool::class, ['base64' => base64_encode(pdfBytes()), 'filename' => 'brief.pdf'])
        ->assertOk();

    return Media::query()->latest('id')->firstOrFail();
}

it('serves plain public urls when the media disk is public', function (): void {
    $media = uploadPdf($this->user);

    expect($media->getUrl())->toContain('/storage/uploads/')
        ->and($media->getUrl())->not->toContain('signature=');
});

it('serves a signed route on a private disk and downloads non-images', function (): void {
    usePrivateMediaDisk();
    $media = uploadPdf($this->user);

    expect($media->getUrl())->toContain('/media/'.$media->uuid)
        ->and($media->getUrl())->toContain('signature=')
        ->and(parse_url($media->getUrl(), PHP_URL_HOST))->toBe(parse_url((string) config('app.url'), PHP_URL_HOST));

    $this->get($media->getUrl())
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=brief.pdf')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('renders images inline on a private disk', function (): void {
    usePrivateMediaDisk();
    RelaticleServer::actingAs($this->user)
        ->tool(UploadFileTool::class, ['base64' => base64_encode(onePixelPng()), 'filename' => 'pixel.png'])
        ->assertOk();
    $media = Media::query()->latest('id')->firstOrFail();

    $this->get($media->getUrl())
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename=pixel.png');
});

it('falls back to the stored file name when original_name is absent', function (): void {
    usePrivateMediaDisk();

    $media = $this->team->addMediaFromString(pdfBytes())
        ->usingFileName('brief.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);

    $this->get($media->getUrl())
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename='.$media->file_name);
});

it('serves agent files with a safe original download name', function (): void {
    usePrivateMediaDisk();

    RelaticleServer::actingAs($this->user)
        ->tool(UploadFileTool::class, [
            'base64' => base64_encode(pdfBytes()),
            'filename' => "../folder\\brief\n.pdf",
        ])
        ->assertOk();
    $media = Media::query()->latest('id')->firstOrFail();

    $this->get($media->getUrl())
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=brief.pdf');

    expect($media->getCustomProperty('original_name'))->toBe('brief.pdf');
});

it('refuses an unsigned or expired private url', function (): void {
    usePrivateMediaDisk();
    $media = uploadPdf($this->user);

    $this->get(route('media.show', ['media' => $media->uuid]))->assertForbidden();

    $url = $media->getUrl();
    $this->travel(31)->minutes();
    $this->get($url)->assertForbidden();
});

it('keeps company logos on the public disk regardless of the switch', function (): void {
    usePrivateMediaDisk();
    $company = Company::factory()->create(['team_id' => $this->team->getKey()]);

    $logo = $company->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(MediaCollection::Logo->value);

    expect($logo->disk)->toBe('public')
        ->and($logo->getUrl())->not->toContain('signature=');
});

it('keeps workspace logos on the public disk regardless of the switch', function (): void {
    usePrivateMediaDisk();

    $logo = $this->team->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(Team::LOGO_MEDIA_COLLECTION);

    expect($logo->disk)->toBe('public')
        ->and($logo->getUrl())->not->toContain('signature=');
});
