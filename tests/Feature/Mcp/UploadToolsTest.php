<?php

declare(strict_types=1);

use App\Actions\Upload\StoreAgentUpload;
use App\Enums\MediaCollection;
use App\Exceptions\UploadException;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\CreateUploadUrlTool;
use App\Mcp\Tools\UploadFileTool;
use App\Models\User;
use App\Support\Media\TemporaryUploads;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(StoreAgentUpload::class, TemporaryUploads::class, CreateUploadUrlTool::class, UploadFileTool::class);

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

describe('StoreAgentUpload', function (): void {
    it('fetches a public https url into pending uploads', function (): void {
        resolveHostsTo(['93.184.216.34']);
        Http::fake(['https://cdn.example.com/*' => Http::response(pdfBytes(), 200, ['Content-Type' => 'application/pdf'])]);

        $media = resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['source_url' => 'https://cdn.example.com/brief.pdf']);

        expect($media->collection_name)->toBe(MediaCollection::PendingUploads->value)
            ->and($media->mime_type)->toBe('application/pdf')
            ->and($media->getCustomProperty('source'))->toBe('url')
            ->and($media->getCustomProperty('original_name'))->toBe('brief.pdf');
    });

    it('reports an unreachable url', function (): void {
        resolveHostsTo(['93.184.216.34']);
        Http::fake(['https://cdn.example.com/*' => Http::response('', 404)]);

        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['source_url' => 'https://cdn.example.com/missing.pdf']))
            ->toThrow(UploadException::class, __('uploads.errors.unreachable'));
    });

    it('reports a url the guard refuses', function (): void {
        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['source_url' => 'http://169.254.169.254/latest']))
            ->toThrow(UploadException::class, __('uploads.errors.url_not_allowed'));
    });

    it('rejects a fetched body over 10 MB', function (): void {
        resolveHostsTo(['93.184.216.34']);
        Http::fake(['https://cdn.example.com/*' => Http::response(str_repeat('a', 10 * 1024 * 1024 + 1), 200)]);

        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['source_url' => 'https://cdn.example.com/huge.bin']))
            ->toThrow(UploadException::class, __('uploads.errors.too_large', ['max' => 10]));
    });

    it('decodes base64 into pending uploads', function (): void {
        $media = resolve(StoreAgentUpload::class)->execute($this->user, $this->team, [
            'base64' => base64_encode(onePixelPng()),
            'filename' => 'pixel.png',
        ]);

        expect($media->mime_type)->toBe('image/png')
            ->and($media->getCustomProperty('source'))->toBe('base64')
            ->and($media->getCustomProperty('original_name'))->toBe('pixel.png');
    });

    it('rejects base64 over 5 MB decoded', function (): void {
        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, [
            'base64' => base64_encode(str_repeat('a', 5 * 1024 * 1024 + 1)),
            'filename' => 'big.pdf',
        ]))->toThrow(UploadException::class, __('uploads.errors.too_large', ['max' => 5]));
    });

    it('rejects malformed base64', function (): void {
        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['base64' => '***', 'filename' => 'x.pdf']))
            ->toThrow(UploadException::class, __('uploads.errors.invalid_base64'));
    });

    it('reports no source when none of source_url, base64, or upload_id is given', function (): void {
        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['base64' => '', 'filename' => 'x.pdf']))
            ->toThrow(UploadException::class, __('uploads.errors.no_source'));
    });

    it('moves a signed-put temp file into pending uploads', function (): void {
        $name = TemporaryUploads::newName('report.pdf');
        TemporaryUploads::disk()->put(TemporaryUploads::path($name), pdfBytes());

        $media = resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['upload_id' => $name]);

        expect($media->getCustomProperty('source'))->toBe('signed_put')
            ->and($media->mime_type)->toBe('application/pdf');
        TemporaryUploads::disk()->assertMissing(TemporaryUploads::path($name));
    });

    it('rejects a temp file over the 10 MB ceiling', function (): void {
        $name = TemporaryUploads::newName('report.pdf');
        TemporaryUploads::disk()->put(TemporaryUploads::path($name), str_repeat('a', 10 * 1024 * 1024 + 1));

        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['upload_id' => $name]))
            ->toThrow(UploadException::class, __('uploads.errors.too_large', ['max' => 10]));
    });

    it('reports a missing or malformed upload id', function (): void {
        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['upload_id' => '../etc/passwd']))
            ->toThrow(UploadException::class, __('uploads.errors.not_found'));

        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['upload_id' => TemporaryUploads::newName('gone.pdf')]))
            ->toThrow(UploadException::class, __('uploads.errors.not_found'));
    });

    it('rejects a temp name whose extension is outside the allowlist', function (): void {
        expect(fn (): string => TemporaryUploads::newName('page.html'))
            ->toThrow(UploadException::class, __('uploads.errors.mime_not_allowed', ['mime' => 'html']));
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
            ->assertHasErrors([__('uploads.errors.mime_not_allowed', ['mime' => 'html'])]);
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
    it('stores the body under tmp on the local disk and answers 204', function (): void {
        $name = TemporaryUploads::newName('deck.pdf');
        $url = URL::temporarySignedRoute('mcp.uploads.receive', now()->addMinutes(5), ['upload' => $name]);

        $this->call('PUT', $url, [], [], [], ['CONTENT_LENGTH' => strlen(pdfBytes()), 'CONTENT_TYPE' => 'application/pdf'], pdfBytes())
            ->assertNoContent();

        TemporaryUploads::disk()->assertExists(TemporaryUploads::path($name));
    });

    it('rejects an unsigned request', function (): void {
        $this->put(route('mcp.uploads.receive', ['upload' => TemporaryUploads::newName('deck.pdf')]), [], ['Content-Length' => '10'])
            ->assertForbidden();
    });

    it('rejects a missing or oversized content length before reading', function (): void {
        $name = TemporaryUploads::newName('deck.pdf');
        $url = URL::temporarySignedRoute('mcp.uploads.receive', now()->addMinutes(5), ['upload' => $name]);

        $this->call('PUT', $url, [], [], [], ['CONTENT_LENGTH' => (string) (10 * 1024 * 1024 + 1)], 'x')
            ->assertStatus(413);
        $this->call('PUT', $url, [], [], [], [], 'x')
            ->assertStatus(411);

        TemporaryUploads::disk()->assertMissing(TemporaryUploads::path($name));
    });

    it('rejects a malformed upload name', function (): void {
        $url = URL::temporarySignedRoute('mcp.uploads.receive', now()->addMinutes(5), ['upload' => 'nope.pdf']);

        $this->call('PUT', $url, [], [], [], ['CONTENT_LENGTH' => '1'], 'x')->assertNotFound();
    });
});

describe('upload-file', function (): void {
    it('stores a base64 file and returns the path to put in a file-upload field', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => base64_encode(pdfBytes()), 'filename' => 'brief.pdf'])
            ->assertOk()
            ->assertSee('"path"')
            ->assertSee('"mime_type"')
            ->assertSee('[brief.pdf](');

        expect(Media::query()->where('collection_name', MediaCollection::PendingUploads->value)->count())->toBe(1);
    });

    it('suggests image markdown for images', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => base64_encode(onePixelPng()), 'filename' => 'pixel.png'])
            ->assertOk()
            ->assertSee('![pixel.png](');
    });

    it('accepts a completed signed put by upload id', function (): void {
        $name = TemporaryUploads::newName('deck.pdf');
        TemporaryUploads::disk()->put(TemporaryUploads::path($name), pdfBytes());

        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['upload_id' => $name])
            ->assertOk()
            ->assertSee('"mime_type"');

        expect(Media::query()->latest('id')->firstOrFail()->mime_type)->toBe('application/pdf');
    });

    it('requires exactly one source', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, [])
            ->assertHasErrors();

        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => base64_encode(pdfBytes()), 'filename' => 'a.pdf', 'upload_id' => TemporaryUploads::newName('b.pdf')])
            ->assertHasErrors();
    });

    it('returns the translated error for a refused type', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>'), 'filename' => 'a.svg'])
            ->assertHasErrors([__('uploads.errors.mime_not_allowed', ['mime' => 'image/svg+xml'])]);
    });

    it('limits a workspace to 60 uploads per hour across both tools', function (): void {
        RateLimiter::clear("mcp-uploads:{$this->team->getKey()}");

        foreach (range(1, 60) as $i) {
            RelaticleServer::actingAs($this->user)
                ->tool(CreateUploadUrlTool::class, ['filename' => "f{$i}.pdf"])
                ->assertOk();
        }

        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => base64_encode(pdfBytes()), 'filename' => 'late.pdf'])
            ->assertHasErrors([__('uploads.errors.rate_limited')]);
    });

    it('requires the create ability', function (): void {
        $token = $this->user->createToken('test', ['read']);
        $this->user->withAccessToken($token->accessToken);

        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => base64_encode(pdfBytes()), 'filename' => 'a.pdf'])
            ->assertHasErrors(['Invalid ability provided.']);
    });
});
