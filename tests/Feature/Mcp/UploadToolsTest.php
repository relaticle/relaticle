<?php

declare(strict_types=1);

use App\Actions\Upload\StoreAgentUpload;
use App\Enums\MediaCollection;
use App\Exceptions\UploadException;
use App\Http\Controllers\Mcp\ReceiveUploadController;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\CreateUploadUrlTool;
use App\Mcp\Tools\UploadFileTool;
use App\Models\User;
use App\Support\Media\TemporaryUploads;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(StoreAgentUpload::class, ReceiveUploadController::class, TemporaryUploads::class, CreateUploadUrlTool::class, UploadFileTool::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
});

describe('StoreAgentUpload', function (): void {
    it('fetches a public https url into pending uploads', function (): void {
        resolveHostsTo(['93.184.216.34']);
        Http::fake(['https://cdn.example.com/*' => Http::response(pdfBytes(), 200, ['Content-Type' => 'application/pdf'])]);

        $media = resolve(StoreAgentUpload::class)->execute($this->user, $this->workspace, ['source_url' => 'https://cdn.example.com/brief.pdf']);

        expect($media->collection_name)->toBe(MediaCollection::PendingUploads->value)
            ->and($media->mime_type)->toBe('application/pdf')
            ->and($media->getCustomProperty('source'))->toBe('url')
            ->and($media->name)->toBe('brief.pdf');
    });

    it('reports an unreachable url', function (): void {
        resolveHostsTo(['93.184.216.34']);
        Http::fake(['https://cdn.example.com/*' => Http::response('', 404)]);

        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->workspace, ['source_url' => 'https://cdn.example.com/missing.pdf']))
            ->toThrow(UploadException::class, __('uploads.errors.unreachable'));
    });

    it('reports a url the guard refuses', function (): void {
        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->workspace, ['source_url' => 'http://169.254.169.254/latest']))
            ->toThrow(UploadException::class, __('uploads.errors.url_not_allowed'));
    });

    it('rejects a fetched body over 10 MB', function (): void {
        resolveHostsTo(['93.184.216.34']);
        Http::fake(['https://cdn.example.com/*' => Http::response(str_repeat('a', 10 * 1024 * 1024 + 1), 200)]);

        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->workspace, ['source_url' => 'https://cdn.example.com/huge.bin']))
            ->toThrow(UploadException::class, __('uploads.errors.too_large', ['max' => 10]));
    });

    it('decodes base64 into pending uploads', function (): void {
        $media = resolve(StoreAgentUpload::class)->execute($this->user, $this->workspace, [
            'base64' => base64_encode(onePixelPng()),
            'filename' => 'pixel.png',
        ]);

        expect($media->mime_type)->toBe('image/png')
            ->and($media->getCustomProperty('source'))->toBe('base64')
            ->and($media->name)->toBe('pixel.png');
    });

    it('rejects base64 over 10 MB decoded', function (): void {
        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->workspace, [
            'base64' => base64_encode(str_repeat('a', 10 * 1024 * 1024 + 1)),
            'filename' => 'big.pdf',
        ]))->toThrow(UploadException::class, __('uploads.errors.too_large', ['max' => 10]));
    });

    it('rejects malformed base64', function (): void {
        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->workspace, ['base64' => '***', 'filename' => 'x.pdf']))
            ->toThrow(UploadException::class, __('uploads.errors.invalid_base64'));
    });

    it('reports no source when none of source_url, base64, or upload_id is given', function (): void {
        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->workspace, ['base64' => '', 'filename' => 'x.pdf']))
            ->toThrow(UploadException::class, __('uploads.errors.no_source'));
    });

    it('moves a signed-put temp file into pending uploads', function (): void {
        $name = TemporaryUploads::newName('report.pdf', (string) $this->workspace->getKey());
        TemporaryUploads::disk()->put(TemporaryUploads::path($name), pdfBytes());

        $media = resolve(StoreAgentUpload::class)->execute($this->user, $this->workspace, ['upload_id' => $name]);

        expect($media->getCustomProperty('source'))->toBe('signed_put')
            ->and($media->mime_type)->toBe('application/pdf');
        TemporaryUploads::disk()->assertMissing(TemporaryUploads::path($name));
    });

    it('rejects a temp file over the 10 MB ceiling', function (): void {
        $name = TemporaryUploads::newName('report.pdf', (string) $this->workspace->getKey());
        TemporaryUploads::disk()->put(TemporaryUploads::path($name), str_repeat('a', 10 * 1024 * 1024 + 1));

        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->workspace, ['upload_id' => $name]))
            ->toThrow(UploadException::class, __('uploads.errors.too_large', ['max' => 10]));
    });

    it('reports a missing or malformed upload id', function (): void {
        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->workspace, ['upload_id' => '../etc/passwd']))
            ->toThrow(UploadException::class, __('uploads.errors.not_found'));

        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->workspace, ['upload_id' => TemporaryUploads::newName('gone.pdf', (string) $this->workspace->getKey())]))
            ->toThrow(UploadException::class, __('uploads.errors.not_found'));
    });

    it('accepts the longest upload id create-upload-url can mint', function (): void {
        $filename = str_repeat('a-very-long-report-name-', 5).'.pdf';
        $upload = TemporaryUploads::newName($filename, (string) $this->workspace->getKey());
        TemporaryUploads::disk()->put(TemporaryUploads::path($upload), pdfBytes());

        expect(strlen($upload))->toBeLessThanOrEqual(TemporaryUploads::MAX_NAME_LENGTH);

        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['upload_id' => $upload])
            ->assertOk();

        expect(Media::query()->latest('id')->firstOrFail()->name)->toStartWith('a-very-long-report-name-');
    });

    it('names a signed-put upload after the file the agent asked to upload', function (): void {
        $name = TemporaryUploads::newName('Quarterly Report.pdf', (string) $this->workspace->getKey());
        TemporaryUploads::disk()->put(TemporaryUploads::path($name), pdfBytes());

        $media = resolve(StoreAgentUpload::class)->execute($this->user, $this->workspace, ['upload_id' => $name]);

        expect($media->name)->toBe('quarterly-report.pdf');
    });

    it('prefers an explicit filename over the one rebuilt from the upload id', function (): void {
        $name = TemporaryUploads::newName('Quarterly Report.pdf', (string) $this->workspace->getKey());
        TemporaryUploads::disk()->put(TemporaryUploads::path($name), pdfBytes());

        $media = resolve(StoreAgentUpload::class)->execute(
            $this->user,
            $this->workspace,
            ['upload_id' => $name, 'filename' => 'Quarterly Report.pdf'],
        );

        expect($media->name)->toBe('Quarterly Report.pdf');
    });

    it('keeps an unsluggable filename inside the upload id pattern', function (): void {
        $name = TemporaryUploads::newName('????.pdf', (string) $this->workspace->getKey());

        expect(TemporaryUploads::isValidName($name))->toBeTrue()
            ->and(TemporaryUploads::displayName($name))->toBe('file.pdf');
    });

    it('refuses an upload id whose name segment carries a path', function (): void {
        $workspaceId = (string) $this->workspace->getKey();

        expect(TemporaryUploads::isValidName(strtoupper($workspaceId).'.'.Str::ulid().'../../etc.pdf'))->toBeFalse()
            ->and(TemporaryUploads::isValidName(strtoupper($workspaceId).'.'.Str::ulid().'.a/b.pdf'))->toBeFalse()
            ->and(TemporaryUploads::belongsToWorkspace(strtoupper($workspaceId).'.'.Str::ulid().'.a.b.pdf', $workspaceId))->toBeFalse();
    });

    it('rejects a temp name whose extension is outside the allowlist', function (): void {
        expect(fn (): string => TemporaryUploads::newName('page.html', (string) $this->workspace->getKey()))
            ->toThrow(UploadException::class, 'Files of type html are not accepted. Allowed: pdf, doc, docx, xlsx, pptx, jpg, png, gif, webp, jpeg.');
    });
});

describe('create-upload-url', function (): void {
    it('returns a five minute signed put url', function (): void {
        $this->freezeTime();

        RelaticleServer::actingAs($this->user)
            ->tool(CreateUploadUrlTool::class, ['filename' => 'deck.pdf'])
            ->assertOk()
            ->assertSee('"upload_id"')
            ->assertSee('/uploads/')
            ->assertSee('signature=')
            ->assertSee(now()->addMinutes(5)->toIso8601String());
    });

    it('rejects a filename outside the allowlist', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateUploadUrlTool::class, ['filename' => 'page.html'])
            ->assertHasErrors(['Files of type html are not accepted. Allowed: pdf, doc, docx, xlsx, pptx, jpg, png, gif, webp, jpeg.']);
    });

    it('names the missing extension instead of an empty type', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateUploadUrlTool::class, ['filename' => 'report'])
            ->assertHasErrors(['Give the file name an extension. Allowed: pdf, doc, docx, xlsx, pptx, jpg, png, gif, webp, jpeg.']);
    });

    it('requires the create ability', function (): void {
        $token = $this->user->createToken('test', ['read']);
        $this->user->withAccessToken($token->accessToken);

        RelaticleServer::actingAs($this->user)
            ->tool(CreateUploadUrlTool::class, ['filename' => 'deck.pdf'])
            ->assertHasErrors(['Invalid ability provided.']);
    });

});

describe('signed put receiver', function (): void {
    it('refuses to overwrite an upload being finalized', function (): void {
        $name = TemporaryUploads::newName('deck.pdf', (string) $this->workspace->getKey());
        $url = URL::temporarySignedRoute('mcp.uploads.receive', now()->addMinutes(5), ['upload' => $name]);
        $lock = Cache::lock("mcp-upload:{$name}", 60);
        $lock->get();

        try {
            $this->call('PUT', $url, [], [], [], ['CONTENT_LENGTH' => strlen(pdfBytes())], pdfBytes())
                ->assertConflict();
            TemporaryUploads::disk()->assertMissing(TemporaryUploads::path($name));
        } finally {
            $lock->release();
        }
    });

    it('does not acknowledge a failed write over an existing temporary upload', function (): void {
        $name = TemporaryUploads::newName('deck.pdf', (string) $this->workspace->getKey());
        $url = URL::temporarySignedRoute('mcp.uploads.receive', now()->addMinutes(5), ['upload' => $name]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('writeStream')->once()->andReturnFalse();
        $disk->shouldReceive('size')->zeroOrMoreTimes()->andReturn(100);
        $disk->shouldReceive('delete')->once()->with(TemporaryUploads::path($name))->andReturnTrue();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->call('PUT', $url, [], [], [], ['CONTENT_LENGTH' => strlen(pdfBytes())], pdfBytes())
            ->assertServiceUnavailable();
    });

    it('stores the body under tmp on the local disk and answers 204', function (): void {
        $name = TemporaryUploads::newName('deck.pdf', (string) $this->workspace->getKey());
        $url = URL::temporarySignedRoute('mcp.uploads.receive', now()->addMinutes(5), ['upload' => $name]);

        $this->call('PUT', $url, [], [], [], ['CONTENT_LENGTH' => strlen(pdfBytes()), 'CONTENT_TYPE' => 'application/pdf'], pdfBytes())
            ->assertNoContent();

        TemporaryUploads::disk()->assertExists(TemporaryUploads::path($name));
    });

    it('rejects an unsigned request', function (): void {
        $this->put(route('mcp.uploads.receive', ['upload' => TemporaryUploads::newName('deck.pdf', (string) $this->workspace->getKey())]), [], ['Content-Length' => '10'])
            ->assertForbidden();
    });

    it('rejects a missing or oversized content length before reading', function (): void {
        $name = TemporaryUploads::newName('deck.pdf', (string) $this->workspace->getKey());
        $url = URL::temporarySignedRoute('mcp.uploads.receive', now()->addMinutes(5), ['upload' => $name]);

        $this->call('PUT', $url, [], [], [], ['CONTENT_LENGTH' => (string) (10 * 1024 * 1024 + 1)], 'x')
            ->assertStatus(413);
        $this->call('PUT', $url, [], [], [], [], 'x')
            ->assertStatus(411);

        TemporaryUploads::disk()->assertMissing(TemporaryUploads::path($name));
    });

    it('rejects a body larger than the content length it declared', function (): void {
        $name = TemporaryUploads::newName('deck.pdf', (string) $this->workspace->getKey());
        $url = URL::temporarySignedRoute('mcp.uploads.receive', now()->addMinutes(5), ['upload' => $name]);

        $this->call('PUT', $url, [], [], [], ['CONTENT_LENGTH' => '1'], str_repeat('x', 10 * 1024 * 1024 + 1))
            ->assertStatus(413);

        TemporaryUploads::disk()->assertMissing(TemporaryUploads::path($name));
    });

    it('rejects a malformed upload name', function (): void {
        $url = URL::temporarySignedRoute('mcp.uploads.receive', now()->addMinutes(5), ['upload' => 'nope.pdf']);

        $this->call('PUT', $url, [], [], [], ['CONTENT_LENGTH' => '1'], 'x')->assertNotFound();
    });
});

describe('upload-file', function (): void {
    it('refuses simultaneous finalization of a signed upload', function (): void {
        $name = TemporaryUploads::newName('deck.pdf', (string) $this->workspace->getKey());
        TemporaryUploads::disk()->put(TemporaryUploads::path($name), pdfBytes());
        $lock = Cache::lock("mcp-upload:{$name}", 60);
        $lock->get();

        try {
            RelaticleServer::actingAs($this->user)->tool(UploadFileTool::class, ['upload_id' => $name])
                ->assertHasErrors(['The upload is being processed. Try again shortly.']);
            expect(Media::query()->count())->toBe(0);
        } finally {
            $lock->release();
        }

        RelaticleServer::actingAs($this->user)->tool(UploadFileTool::class, ['upload_id' => $name])->assertOk();
        RelaticleServer::actingAs($this->user)->tool(UploadFileTool::class, ['upload_id' => $name])
            ->assertHasErrors([__('uploads.errors.not_found')]);
        expect(Media::query()->count())->toBe(1);
    });

    it('bounds the display name of a file fetched from a long url', function (): void {
        resolveHostsTo(['93.184.216.34']);
        Http::fake(['https://cdn.example.com/*' => Http::response(pdfBytes(), 200, ['Content-Type' => 'application/pdf'])]);

        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['source_url' => 'https://cdn.example.com/'.str_repeat('a', 256).'.pdf'])
            ->assertOk();

        expect(mb_strlen(Media::query()->latest('id')->firstOrFail()->name))->toBeLessThanOrEqual(255);
    });

    it('stores a base64 file and returns its file_id and markdown', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => base64_encode(pdfBytes()), 'filename' => 'brief.pdf'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
                ->where('file_id', fn (string $id): bool => Media::query()->where('uuid', $id)->exists())
                ->where('name', 'brief.pdf')
                ->where('mime_type', 'application/pdf')
                ->where('url', fn (string $url): bool => str_contains($url, 'signature='))
                ->where('suggested_markdown', fn (string $markdown): bool => str_starts_with($markdown, '[brief.pdf](') && ! str_contains($markdown, 'signature='))
                ->etc());

        expect(Media::query()->where('collection_name', MediaCollection::PendingUploads->value)->count())->toBe(1);
    });

    it('suggests image markdown with a stable media link for images', function (): void {
        $response = RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => base64_encode(onePixelPng()), 'filename' => 'pixel.png'])
            ->assertOk();
        $media = Media::query()->latest('id')->firstOrFail();

        $response->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('suggested_markdown', '![pixel.png]('.route('media.show', ['media' => $media->uuid]).')')
            ->etc());
    });

    it('accepts a completed signed put by upload id', function (): void {
        $upload = TemporaryUploads::newName('deck.pdf', (string) $this->workspace->getKey());
        TemporaryUploads::disk()->put(TemporaryUploads::path($upload), pdfBytes());

        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['upload_id' => $upload, 'filename' => 'deck.pdf'])
            ->assertOk()
            ->assertSee('"mime_type"')
            ->assertSee('[deck.pdf](');

        $media = Media::query()->latest('id')->firstOrFail();
        expect($media->mime_type)->toBe('application/pdf')
            ->and($media->name)->toBe('deck.pdf');
    });

    it('prevents another workspace from finalizing a signed put', function (): void {
        $upload = TemporaryUploads::newName('deck.pdf', (string) $this->workspace->getKey());
        TemporaryUploads::disk()->put(TemporaryUploads::path($upload), pdfBytes());
        $otherUser = User::factory()->withPersonalWorkspace()->create();

        RelaticleServer::actingAs($otherUser)
            ->tool(UploadFileTool::class, ['upload_id' => $upload, 'filename' => 'deck.pdf'])
            ->assertHasErrors([__('uploads.errors.not_found')]);

        TemporaryUploads::disk()->assertExists(TemporaryUploads::path($upload));

        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['upload_id' => $upload, 'filename' => 'deck.pdf'])
            ->assertOk();
    });

    it('requires exactly one source', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, [])
            ->assertHasErrors();

        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => base64_encode(pdfBytes()), 'filename' => 'a.pdf', 'upload_id' => TemporaryUploads::newName('b.pdf', (string) $this->workspace->getKey())])
            ->assertHasErrors();
    });

    it('returns the translated error for a refused type', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>'), 'filename' => 'a.svg'])
            ->assertHasErrors(['Files of type image/svg+xml are not accepted. Allowed: pdf, doc, docx, xlsx, pptx, jpg, png, gif, webp, jpeg.']);
    });

    it('escapes markdown-breaking characters in the suggested label', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, [
                'base64' => base64_encode(pdfBytes()),
                'filename' => 'a](https://evil.test) x.pdf',
            ])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
                ->where('suggested_markdown', fn (string $markdown): bool => substr_count($markdown, '](') === 1)
                ->etc());
    });

    it('limits a workspace to 60 uploads per hour', function (): void {
        $key = "mcp-uploads:{$this->workspace->getKey()}";
        RateLimiter::clear($key);

        foreach (range(1, 59) as $i) {
            RateLimiter::hit($key, 3600);
        }

        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => base64_encode(pdfBytes()), 'filename' => 'last.pdf'])
            ->assertOk();

        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => base64_encode(pdfBytes()), 'filename' => 'late.pdf'])
            ->assertHasErrors([__('uploads.errors.rate_limited')]);

        RelaticleServer::actingAs($this->user)
            ->tool(CreateUploadUrlTool::class, ['filename' => 'still-fine.pdf'])
            ->assertOk();

        expect(Media::query()->count())->toBe(1);
    });

    it('requires the create ability', function (): void {
        $token = $this->user->createToken('test', ['read']);
        $this->user->withAccessToken($token->accessToken);

        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => base64_encode(pdfBytes()), 'filename' => 'a.pdf'])
            ->assertHasErrors(['Invalid ability provided.']);
    });
    it('counts refused uploads toward the workspace hourly limit', function (): void {
        $key = "mcp-uploads:{$this->workspace->getKey()}";
        RateLimiter::increment($key, 3600, 59);

        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => '***', 'filename' => 'bad.pdf'])
            ->assertHasErrors([__('uploads.errors.invalid_base64')]);

        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => base64_encode(pdfBytes()), 'filename' => 'late.pdf'])
            ->assertHasErrors([__('uploads.errors.rate_limited')]);

        expect(Media::query()->count())->toBe(0);
    });
});
