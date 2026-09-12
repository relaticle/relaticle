<?php

declare(strict_types=1);

use App\Actions\Upload\StorePendingUpload;
use App\Enums\MediaCollection;
use App\Enums\UploadSource;
use App\Exceptions\UploadException;
use App\Models\Company;
use App\Models\User;
use App\Support\Media\UploadPathGenerator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(StorePendingUpload::class, UploadPathGenerator::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

function tempFileWith(string $bytes, string $extension): string
{
    $path = tempnam(sys_get_temp_dir(), 'upload').'.'.$extension;
    file_put_contents($path, $bytes);

    return $path;
}

it('keeps company logos on their existing id-keyed path', function (): void {
    $company = Company::factory()->create(['team_id' => $this->team->getKey()]);

    $media = $company->addMediaFromString(onePixelPng())
        ->usingFileName('logo.png')
        ->toMediaCollection(MediaCollection::Logo->value);

    expect($media->getPathRelativeToRoot())->toBe("{$media->getKey()}/logo.png")
        ->and($media->disk)->toBe('public');
});

it('stores a pending upload under uploads/{uuid} with its provenance', function (): void {
    $media = resolve(StorePendingUpload::class)->execute(
        $this->user,
        $this->team,
        tempFileWith(pdfBytes(), 'pdf'),
        'Contract v2.pdf',
        UploadSource::Panel,
    );

    expect($media->collection_name)->toBe(MediaCollection::PendingUploads->value)
        ->and($media->model_id)->toBe($this->team->getKey())
        ->and($media->getPathRelativeToRoot())->toMatch('#^uploads/[0-9a-f-]{36}/[0-9A-Z]{26}\.pdf$#')
        ->and($media->mime_type)->toBe('application/pdf')
        ->and($media->name)->toBe('Contract v2')
        ->and($media->getCustomProperty('team_id'))->toBe($this->team->getKey())
        ->and($media->getCustomProperty('uploaded_by'))->toBe($this->user->getKey())
        ->and($media->getCustomProperty('source'))->toBe('panel')
        ->and($media->getCustomProperty('original_name'))->toBe('Contract v2.pdf');

    Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
});

it('names the file by the sniffed type, not the claimed extension', function (): void {
    $media = resolve(StorePendingUpload::class)->execute(
        $this->user,
        $this->team,
        tempFileWith(onePixelPng(), 'pdf'),
        'shot.pdf',
        UploadSource::Panel,
    );

    expect($media->mime_type)->toBe('image/png')
        ->and($media->file_name)->toEndWith('.png');
});

it('rejects a type outside the allowlist', function (): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

    expect(fn (): Media => resolve(StorePendingUpload::class)->execute(
        $this->user,
        $this->team,
        tempFileWith($svg, 'svg'),
        'evil.svg',
        UploadSource::Panel,
    ))->toThrow(UploadException::class, __('uploads.errors.mime_not_allowed', ['mime' => 'image/svg+xml']));
});

it('rejects a file over the 10 MB ceiling', function (): void {
    $path = tempFileWith(pdfBytes(), 'pdf');
    $handle = fopen($path, 'ab');
    ftruncate($handle, 10 * 1024 * 1024 + 1);
    fclose($handle);

    expect(fn (): Media => resolve(StorePendingUpload::class)->execute($this->user, $this->team, $path, 'big.pdf', UploadSource::Panel))
        ->toThrow(UploadException::class, __('uploads.errors.too_large', ['max' => 10]));
});

it('reports a missing source file as not found', function (): void {
    expect(fn (): Media => resolve(StorePendingUpload::class)->execute(
        $this->user,
        $this->team,
        sys_get_temp_dir().'/does-not-exist-'.Str::random(8),
        'a.pdf',
        UploadSource::Panel,
    ))->toThrow(UploadException::class, __('uploads.errors.not_found'));
});

it('refuses to store for a team the user is not on', function (): void {
    $stranger = User::factory()->withPersonalTeam()->create();

    expect(fn (): Media => resolve(StorePendingUpload::class)->execute(
        $stranger,
        $this->team,
        tempFileWith(pdfBytes(), 'pdf'),
        'a.pdf',
        UploadSource::Panel,
    ))->toThrow(HttpException::class);
});
