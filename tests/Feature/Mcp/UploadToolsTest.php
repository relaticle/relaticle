<?php

declare(strict_types=1);

use App\Actions\Upload\StoreAgentUpload;
use App\Enums\MediaCollection;
use App\Exceptions\UploadException;
use App\Models\User;
use App\Services\Favicon\HostResolver;
use App\Support\Media\TemporaryUploads;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(StoreAgentUpload::class, TemporaryUploads::class);

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

describe('StoreAgentUpload', function (): void {
    it('fetches a public https url into pending uploads', function (): void {
        app()->instance(HostResolver::class, Mockery::mock(new HostResolver)
            ->shouldReceive('addresses')->once()->with('cdn.example.com')->andReturn(['93.184.216.34'])
            ->getMock());
        Http::fake(['https://cdn.example.com/*' => Http::response(pdfBytes(), 200, ['Content-Type' => 'application/pdf'])]);

        $media = resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['source_url' => 'https://cdn.example.com/brief.pdf']);

        expect($media->collection_name)->toBe(MediaCollection::PendingUploads->value)
            ->and($media->mime_type)->toBe('application/pdf')
            ->and($media->getCustomProperty('source'))->toBe('url')
            ->and($media->getCustomProperty('original_name'))->toBe('brief.pdf');
    });

    it('reports an unreachable url', function (): void {
        app()->instance(HostResolver::class, Mockery::mock(new HostResolver)
            ->shouldReceive('addresses')->once()->with('cdn.example.com')->andReturn(['93.184.216.34'])
            ->getMock());
        Http::fake(['https://cdn.example.com/*' => Http::response('', 404)]);

        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['source_url' => 'https://cdn.example.com/missing.pdf']))
            ->toThrow(UploadException::class, __('uploads.errors.unreachable'));
    });

    it('reports a url the guard refuses', function (): void {
        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['source_url' => 'http://169.254.169.254/latest']))
            ->toThrow(UploadException::class, __('uploads.errors.url_not_allowed'));
    });

    it('rejects a fetched body over 10 MB', function (): void {
        app()->instance(HostResolver::class, Mockery::mock(new HostResolver)
            ->shouldReceive('addresses')->once()->with('cdn.example.com')->andReturn(['93.184.216.34'])
            ->getMock());
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
