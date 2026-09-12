<?php

declare(strict_types=1);

use App\Actions\Upload\StoreAgentUpload;
use App\Enums\MediaCollection;
use App\Exceptions\UploadException;
use App\Models\User;
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
        Http::fake(['https://1.1.1.1/*' => Http::response(pdfBytes(), 200, ['Content-Type' => 'application/pdf'])]);

        $media = resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['source_url' => 'https://1.1.1.1/brief.pdf']);

        expect($media->collection_name)->toBe(MediaCollection::PendingUploads->value)
            ->and($media->mime_type)->toBe('application/pdf')
            ->and($media->getCustomProperty('source'))->toBe('url')
            ->and($media->getCustomProperty('original_name'))->toBe('brief.pdf');
    });

    it('reports an unreachable url', function (): void {
        Http::fake(['https://1.1.1.1/*' => Http::response('', 404)]);

        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['source_url' => 'https://1.1.1.1/missing.pdf']))
            ->toThrow(UploadException::class, __('uploads.errors.unreachable'));
    });

    it('reports a url the guard refuses', function (): void {
        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['source_url' => 'http://169.254.169.254/latest']))
            ->toThrow(UploadException::class, __('uploads.errors.url_not_allowed'));
    });

    it('rejects a fetched body over 10 MB', function (): void {
        Http::fake(['https://1.1.1.1/*' => Http::response(str_repeat('a', 10 * 1024 * 1024 + 1), 200)]);

        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['source_url' => 'https://1.1.1.1/huge.bin']))
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
        ]))->toThrow(UploadException::class, __('uploads.errors.too_large', ['max' => 10]));
    });

    it('rejects malformed base64', function (): void {
        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['base64' => '***', 'filename' => 'x.pdf']))
            ->toThrow(UploadException::class, __('uploads.errors.invalid_base64'));
    });

    it('moves a signed-put temp file into pending uploads', function (): void {
        $name = TemporaryUploads::newName('report.pdf');
        TemporaryUploads::disk()->put(TemporaryUploads::path($name), pdfBytes());

        $media = resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['upload_id' => $name]);

        expect($media->getCustomProperty('source'))->toBe('signed_put')
            ->and($media->mime_type)->toBe('application/pdf');
        TemporaryUploads::disk()->assertMissing(TemporaryUploads::path($name));
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
