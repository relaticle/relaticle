<?php

declare(strict_types=1);

use App\Console\Commands\BackfillRichEditorAttachmentsCommand;
use App\Enums\MediaCollection;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Relaticle\CustomFields\Services\TenantContextService;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(BackfillRichEditorAttachmentsCommand::class);

beforeEach(function (): void {
    $this->freezeTime();
    Storage::fake('local');
    Storage::fake('public');
    $this->workspace = User::factory()->withPersonalWorkspace()->create()->personalWorkspace();
    $this->body = CustomField::query()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'note')
        ->where('code', 'body')
        ->firstOrFail();
    Storage::disk('public')->put('legacy.png', onePixelPng());
    Storage::disk('public')->put('second.png', onePixelPng());
    Storage::disk('public')->put('untagged.jpg', onePixelPng());
    $this->note = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->second = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $this->untagged = Note::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    TenantContextService::withTenant($this->workspace->getKey(), function (): void {
        $this->note->saveCustomFieldValue($this->body, '<p><img src="https://app.test/storage/legacy.png" alt="old" data-id="legacy.png"></p>');
        $this->second->saveCustomFieldValue($this->body, '<p><img src="https://app.test/storage/second.png" data-id="second.png"></p>');
        $this->untagged->saveCustomFieldValue($this->body, '<p><img src="https://app.test/storage/untagged.jpg"><img src="https://cdn.example.com/external.png"></p>');
    });
});

it('reports without writing by default', function (): void {
    $this->artisan('media:backfill-rich-editor-attachments')
        ->expectsOutputToContain('3 image(s) would be migrated')
        ->assertSuccessful();

    expect(Media::query()->count())->toBe(0);
});

it('creates a media row on the record and rewrites the image with --force, outside any tenant context', function (): void {
    $activities = Activity::query()->count();

    $this->artisan('media:backfill-rich-editor-attachments --force')
        ->expectsOutputToContain('3 image(s) migrated.')
        ->assertSuccessful();

    $media = Media::query()->where('model_id', $this->note->getKey())->firstOrFail();
    $html = (string) TenantContextService::withTenant($this->workspace->getKey(), fn (): mixed => $this->note->refresh()->getCustomFieldValue($this->body));

    expect($media->model_id)->toBe($this->note->getKey())
        ->and($media->collection_name)->toBe(MediaCollection::Attachments->value)
        ->and($media->workspace_id)->toBe($this->workspace->getKey())
        ->and($media->disk)->toBe('local')
        ->and($html)->toContain("data-id=\"{$media->uuid}\"")
        ->and($html)->not->toContain('signature=')
        ->and($html)->toContain('alt="old"')
        ->and($html)->not->toContain('data-id="legacy.png"')
        ->and($html)->not->toContain('storage/legacy.png')
        ->and(Storage::disk('public')->exists('legacy.png'))->toBeTrue()
        ->and(Activity::query()->count())->toBe($activities);
});

it('is idempotent', function (): void {
    $this->artisan('media:backfill-rich-editor-attachments --force')->assertSuccessful();

    $this->artisan('media:backfill-rich-editor-attachments --force')
        ->expectsOutputToContain('0 image(s) migrated.')
        ->assertSuccessful();

    expect(Media::query()->count())->toBe(3);
});

it('tags an untagged public-disk image with its new media uuid and leaves external images alone', function (): void {
    $this->artisan('media:backfill-rich-editor-attachments --force')->assertSuccessful();

    $media = Media::query()->where('model_id', $this->untagged->getKey())->firstOrFail();
    $html = (string) TenantContextService::withTenant($this->workspace->getKey(), fn (): mixed => $this->untagged->refresh()->getCustomFieldValue($this->body));

    expect($media->name)->toBe('untagged.jpg')
        ->and($html)->toBe('<p><img data-id="'.$media->uuid.'"><img src="https://cdn.example.com/external.png"></p>');
});

it('skips an image whose file is gone from the public disk', function (): void {
    Storage::disk('public')->delete('legacy.png');

    $this->artisan('media:backfill-rich-editor-attachments --force')
        ->expectsOutputToContain('legacy.png is missing on the public disk, skipped.')
        ->expectsOutputToContain('2 image(s) migrated.')
        ->assertSuccessful();

    expect(Media::query()->count())->toBe(2)
        ->and(Media::query()->where('model_id', $this->note->getKey())->exists())->toBeFalse();
});

it('skips a legacy image whose type the attachments collection refuses', function (): void {
    Storage::disk('public')->put('legacy.png', '<svg xmlns="http://www.w3.org/2000/svg"/>');

    $this->artisan('media:backfill-rich-editor-attachments --force')
        ->expectsOutputToContain('the attachments collection refuses, skipped.')
        ->expectsOutputToContain('2 image(s) migrated.')
        ->assertSuccessful();

    expect(Media::query()->count())->toBe(2)
        ->and(Media::query()->where('model_id', $this->note->getKey())->exists())->toBeFalse();
});

it('keeps going and still succeeds when one value cannot be migrated', function (): void {
    Media::creating(function (Media $media): void {
        if ($media->name === 'legacy.png') {
            throw new RuntimeException('disk unavailable');
        }
    });

    $this->artisan('media:backfill-rich-editor-attachments --force')->assertSuccessful();

    expect(Media::query()->where('model_id', $this->second->getKey())->count())->toBe(1)
        ->and(Media::query()->where('model_id', $this->note->getKey())->count())->toBe(0);
});
