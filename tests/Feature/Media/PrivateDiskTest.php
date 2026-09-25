<?php

declare(strict_types=1);

use App\Enums\MediaCollection;
use App\Http\Controllers\Media\ShowMediaController;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\UploadFileTool;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaUrlGenerator;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(MediaUrlGenerator::class, ShowMediaController::class, Workspace::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
});

function usePublicMediaDisk(): void
{
    config()->set('media-library.disk_name', 'public');
}

function uploadPdf(User $user): Media
{
    RelaticleServer::actingAs($user)
        ->tool(UploadFileTool::class, ['base64' => base64_encode(pdfBytes()), 'filename' => 'brief.pdf'])
        ->assertOk();

    return Media::query()->latest('id')->firstOrFail();
}

it('serves plain public urls when the media disk is public', function (): void {
    usePublicMediaDisk();
    $media = uploadPdf($this->user);

    expect($media->disk)->toBe('public')
        ->and($media->getUrl())->toContain('/storage/uploads/')
        ->and($media->getUrl())->not->toContain('signature=');
});

it('stores on the private local disk by default and downloads non-images through a signed route', function (): void {
    $media = uploadPdf($this->user);

    expect($media->disk)->toBe('local')
        ->and($media->getUrl())->toContain('/media/'.$media->uuid)
        ->and($media->getUrl())->toContain('signature=')
        ->and(parse_url($media->getUrl(), PHP_URL_HOST))->toBe(parse_url((string) config('app.url'), PHP_URL_HOST));

    $this->get($media->getUrl())
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename=brief.pdf')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('renders images inline on the private disk', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(UploadFileTool::class, ['base64' => base64_encode(onePixelPng()), 'filename' => 'pixel.png'])
        ->assertOk();
    $media = Media::query()->latest('id')->firstOrFail();

    $this->get($media->getUrl())
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename=pixel.png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
});

it('serves agent files with a safe original download name', function (): void {
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

    expect($media->name)->toBe('brief.pdf');
});

it('serves a file whose name has no ascii characters', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(UploadFileTool::class, [
            'base64' => base64_encode(pdfBytes()),
            'filename' => '報告書',
        ])
        ->assertOk();
    $media = Media::query()->latest('id')->firstOrFail();

    $this->get($media->getUrl())->assertOk();
});

it('refuses an unsigned or expired private url', function (): void {
    $media = uploadPdf($this->user);

    $this->get(route('media.show', ['media' => $media->uuid]))->assertForbidden();

    $url = $media->getUrl();
    $this->travel(31)->minutes();
    $this->get($url)->assertForbidden();
});

it('keeps company logos on the public disk', function (): void {
    $company = Company::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    $logo = $company->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(MediaCollection::Logo->value);

    expect($logo->disk)->toBe('public')
        ->and($logo->getUrl())->not->toContain('signature=');
});

it('keeps workspace logos on the public disk', function (): void {
    $logo = $this->workspace->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(Workspace::LOGO_MEDIA_COLLECTION);

    expect($logo->disk)->toBe('public')
        ->and($logo->getUrl())->not->toContain('signature=');
});

it('answers 404 when the file behind a signed url is gone from the disk', function (): void {
    $media = uploadPdf($this->user);
    Storage::disk('local')->delete($media->getPathRelativeToRoot());

    $this->get($media->getUrl())->assertNotFound();
});
