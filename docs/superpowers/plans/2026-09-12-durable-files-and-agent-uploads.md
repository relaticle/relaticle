# Durable Files and Agent Uploads Implementation Plan

> **For agentic workers:** REQUIRED: Use `sdd-lean` to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every durable user file becomes a medialibrary Media row on the model that owns it, one env switch moves them all to a private disk, and an AI agent can upload a file over MCP and set a `file-upload` custom field to the returned path.

**Architecture:** Every upload, from the panel or from MCP, first lands as a Media row in the caller's `Team` `pending-uploads` collection. An observer on `CustomFieldValue` claims referenced pending rows onto the record (an in-place owner write, the file never moves) and deletes record rows the new value dropped. Validation happens before actions in `ValidCustomFields`; the only new action classes are `StorePendingUpload` and `StoreAgentUpload`.

**Tech Stack:** Laravel 13.31, Filament 5.8.1, Pest 5.1.4, relaticle/custom-fields 3.9.1, spatie/laravel-medialibrary 11.23.7, laravel/mcp 0.9.5. No new composer dependency.

**Spec:** `docs/superpowers/specs/2026-09-12-durable-files-and-agent-uploads-design.md`. Read it first; every task below argues from it.

**Skills to activate:** `medialibrary-development`, `mcp-development`, `testing-best-practices`, `spatie-laravel-php`.

## Global Constraints

- Durable user files go through medialibrary with a named collection on the owning model. Import CSVs under `storage/app/imports` and Jetstream profile photos are exempt.
- `logo` collections stay on the public disk. Every collection this plan creates follows `MEDIA_DISK`.
- Paths are `uploads/{uuid}/{file_name}`, model-independent. A stored `file-upload` value is that path, derived from the Media row.
- Ownership changes are attribute writes on the existing Media row. Never call `Media::move()`; it copies and deletes, which changes `uuid` and path.
- Actions stay `final readonly` with one `execute()`. PHPStan forbids Eloquent writes in `App\Mcp`, `App\Http\Controllers`, `App\Filament`, `App\Livewire`; those layers call actions.
- Every user-facing string goes through `__()`. No new PHPStan ignores. 100% type coverage. No comments in tests. No em-dashes anywhere.
- Test files live under `tests/Feature/`, `tests/Arch/` only. Declare `mutates(...)` per file.
- Before every commit: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, `composer test:type-coverage`, the task's tests with `--filter`.
- This workspace branch is `feat/agent-file-uploads-v1`; the PR head is `feat/agent-file-uploads`. Push with `git push origin HEAD:feat/agent-file-uploads`.

## Prerequisites and merge order

1. Tasks 1 to 3 need nothing beyond `main` as of `94e442c59`.
2. Task 4 edits `app/Filament/CustomFields/RichEditorFieldType.php`, created by #695. Before Task 4: confirm #695 is merged, then `git fetch origin && git merge origin/main` and verify the file exists.
3. #606 reworks its team logo into a `logo` collection on `Team` and adds `Team implements HasMedia`. Task 1 adds `HasMedia` to `Team` only if it is not there yet, so either merge order works. After #606 merges, merge `origin/main` again and drop the duplicate if both branches added it.
4. #699 targets `feat/custom-fields-agent-friendly-writes` (merged as #698). Retargeting to `main` needs the founder's say-so; it is not part of any task.

## File map

| File | Responsibility |
|---|---|
| `app/Enums/MediaCollection.php` | collection names: `logo`, `pending-uploads`, `custom-field-{code}` |
| `app/Enums/UploadSource.php` | `panel`, `url`, `base64`, `signed_put` |
| `app/Exceptions/UploadException.php` | one exception, static constructors, translated messages |
| `app/Support/Media/UploadAllowlist.php` | MIME allowlist, extension map, 10 MB ceiling |
| `app/Support/Media/UploadPathGenerator.php` | `uploads/{uuid}/` for everything but `logo` |
| `app/Support/Media/MediaPaths.php` | path ⇄ Media lookups, tenant-scoped by `custom_properties->team_id` |
| `app/Support/Media/UploadClaims.php` | claim referenced pending media onto a record, release dropped media |
| `app/Support/Media/RichContentAttachments.php` | Filament `FileAttachmentProvider`; save, resolve, render, rewrite `src` |
| `app/Support/Media/TemporaryUploads.php` | signed-PUT temp names and paths under `tmp/` |
| `app/Support/Media/MediaUrlGenerator.php` | plain URL on a public disk, signed route on a private one |
| `app/Actions/Upload/StorePendingUpload.php` | sniff, allowlist, size gate, `addMedia` into `pending-uploads` |
| `app/Actions/Upload/StoreAgentUpload.php` | materialise `source_url` / `base64` / `upload_id` into a temp file, then `StorePendingUpload` |
| `app/Filament/CustomFields/FileUploadFieldType.php`, `FileUploadComponent.php`, `FileEntry.php`, `FileColumn.php` | media-backed `file-upload` type |
| `app/Filament/CustomFields/RichContentEntry.php` | infolist entry rendering through the provider |
| `app/Http/Controllers/Mcp/ReceiveUploadController.php` | signed `PUT` receiver |
| `app/Http/Controllers/Media/ShowMediaController.php` | signed private-disk serving |
| `app/Mcp/Tools/CreateUploadUrlTool.php`, `UploadFileTool.php`, `Concerns/LimitsUploads.php` | the two tools and their rate limit |
| `app/Rules/StoredUploadPath.php` | the `file-upload` value rule shared by REST, MCP, chat |
| `app/Console/Commands/PurgePendingUploadsCommand.php`, `BackfillRichEditorAttachmentsCommand.php` | purge and the one-off legacy backfill |
| `lang/en/uploads.php` | upload error strings |
| `.ai/rules/file-uploads.md` | the rule and its two exemptions |

---

### Task 1: Media foundation

**Files:**
- Create: `app/Enums/MediaCollection.php`, `app/Enums/UploadSource.php`, `app/Exceptions/UploadException.php`
- Create: `app/Support/Media/UploadAllowlist.php`, `app/Support/Media/UploadPathGenerator.php`, `app/Support/Media/MediaPaths.php`
- Create: `app/Actions/Upload/StorePendingUpload.php`
- Create: `lang/en/uploads.php`
- Modify: `config/media-library.php:36` (`disk_name` stays `env('MEDIA_DISK', 'public')`), `path_generator`
- Modify: `config/filesystems.php` (add the `media` disk), `.env.example` (add `MEDIA_DISK=public`)
- Modify: `app/Models/Company.php`, `app/Models/Team.php`, `app/Models/People.php`, `app/Models/Opportunity.php`, `app/Models/Task.php`, `app/Models/Note.php`
- Modify: `tests/Pest.php` (two byte helpers)
- Test: `tests/Feature/Media/MediaStorageTest.php`

**Interfaces:**
- Produces: `MediaCollection::Logo`, `MediaCollection::PendingUploads`, `MediaCollection::forCustomField(string $code): string`
- Produces: `UploadAllowlist::MIME_TYPES`, `UploadAllowlist::MAX_BYTES`, `UploadAllowlist::extensionFor(string $mime): ?string`, `UploadAllowlist::isImage(string $mime): bool`, `UploadAllowlist::extensions(): array`
- Produces: `StorePendingUpload::execute(User $user, Team $team, string $path, string $originalName, UploadSource $source): Media`
- Produces: `MediaPaths::uuidFromPath(string $path): ?string`, `MediaPaths::find(string $teamId, string $path): ?Media`, `MediaPaths::findByUuid(string $teamId, string $uuid): ?Media`
- Produces: `UploadException::tooLarge()`, `::mimeNotAllowed(string $mime)`, `::unreachable()`, `::urlNotAllowed()`, `::notFound()`, `::rateLimited()`, `::invalidBase64()`
- Produces: test helpers `pdfBytes(): string`, `onePixelPng(): string`

- [ ] **Step 1: Add the byte helpers to `tests/Pest.php`**

Append after the existing helper functions:

```php
function pdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

function onePixelPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Media/MediaStorageTest.php`:

```php
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
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
        ->toThrow(UploadException::class, __('uploads.errors.too_large'));
});

it('refuses to store for a team the user is not on', function (): void {
    $stranger = User::factory()->withPersonalTeam()->create();

    expect(fn (): Media => resolve(StorePendingUpload::class)->execute(
        $stranger,
        $this->team,
        tempFileWith(pdfBytes(), 'pdf'),
        'a.pdf',
        UploadSource::Panel,
    ))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=MediaStorageTest`
Expected: FAIL, `Class "App\Actions\Upload\StorePendingUpload" not found`.

- [ ] **Step 4: Create the enums and the exception**

`app/Enums/MediaCollection.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum MediaCollection: string
{
    case Logo = 'logo';
    case PendingUploads = 'pending-uploads';

    public static function forCustomField(string $code): string
    {
        return "custom-field-{$code}";
    }
}
```

`app/Enums/UploadSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum UploadSource: string
{
    case Panel = 'panel';
    case Url = 'url';
    case Base64 = 'base64';
    case SignedPut = 'signed_put';
}
```

`app/Exceptions/UploadException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class UploadException extends RuntimeException
{
    public static function tooLarge(): self
    {
        return new self(__('uploads.errors.too_large'));
    }

    public static function mimeNotAllowed(string $mime): self
    {
        return new self(__('uploads.errors.mime_not_allowed', ['mime' => $mime]));
    }

    public static function unreachable(): self
    {
        return new self(__('uploads.errors.unreachable'));
    }

    public static function urlNotAllowed(): self
    {
        return new self(__('uploads.errors.url_not_allowed'));
    }

    public static function notFound(): self
    {
        return new self(__('uploads.errors.not_found'));
    }

    public static function rateLimited(): self
    {
        return new self(__('uploads.errors.rate_limited'));
    }

    public static function invalidBase64(): self
    {
        return new self(__('uploads.errors.invalid_base64'));
    }
}
```

`lang/en/uploads.php`:

```php
<?php

declare(strict_types=1);

return [
    'errors' => [
        'too_large' => 'The file is larger than 10 MB.',
        'mime_not_allowed' => 'Files of type :mime are not accepted. Allowed: pdf, doc, docx, jpeg, png, gif, webp.',
        'unreachable' => 'The URL could not be fetched.',
        'url_not_allowed' => 'Only public https URLs on port 443 can be fetched.',
        'not_found' => 'The upload was not found or has expired.',
        'rate_limited' => 'Upload limit reached: 60 uploads per hour per workspace. Try again later.',
        'invalid_base64' => 'The base64 payload could not be decoded.',
    ],
];
```

- [ ] **Step 5: Create the allowlist, path generator and path resolver**

`app/Support/Media/UploadAllowlist.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Media;

final readonly class UploadAllowlist
{
    /** @var array<string, string> */
    public const array MIME_TYPES = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public const int MAX_BYTES = 10 * 1024 * 1024;

    public static function extensionFor(string $mime): ?string
    {
        return self::MIME_TYPES[$mime] ?? null;
    }

    public static function isImage(string $mime): bool
    {
        return str_starts_with($mime, 'image/') && isset(self::MIME_TYPES[$mime]);
    }

    /** @return list<string> */
    public static function extensions(): array
    {
        return array_values(array_unique([...array_values(self::MIME_TYPES), 'jpeg']));
    }
}
```

`app/Support/Media/UploadPathGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Enums\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

final class UploadPathGenerator extends DefaultPathGenerator
{
    protected function getBasePath(Media $media): string
    {
        if ($media->collection_name === MediaCollection::Logo->value) {
            return parent::getBasePath($media);
        }

        return "uploads/{$media->uuid}";
    }
}
```

`app/Support/Media/MediaPaths.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class MediaPaths
{
    private const string PATH_PATTERN = '#^uploads/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/[^/]+$#';

    public function uuidFromPath(string $path): ?string
    {
        return preg_match(self::PATH_PATTERN, $path, $matches) === 1 ? $matches[1] : null;
    }

    public function find(string $teamId, string $path): ?Media
    {
        $uuid = $this->uuidFromPath($path);

        if ($uuid === null) {
            return null;
        }

        $media = $this->findByUuid($teamId, $uuid);

        if ($media === null || $media->getPathRelativeToRoot() !== $path) {
            return null;
        }

        return $media;
    }

    public function findByUuid(string $teamId, string $uuid): ?Media
    {
        return Media::query()
            ->where('uuid', $uuid)
            ->where('custom_properties->team_id', $teamId)
            ->first();
    }
}
```

- [ ] **Step 6: Create `StorePendingUpload`**

`app/Actions/Upload/StorePendingUpload.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Enums\MediaCollection;
use App\Enums\UploadSource;
use App\Exceptions\UploadException;
use App\Models\Team;
use App\Models\User;
use App\Support\Media\UploadAllowlist;
use finfo;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class StorePendingUpload
{
    public function execute(User $user, Team $team, string $path, string $originalName, UploadSource $source): Media
    {
        abort_unless($user->belongsToTeam($team), 403);

        $size = filesize($path);

        throw_if($size === false || $size > UploadAllowlist::MAX_BYTES, UploadException::tooLarge());

        $mime = (string) new finfo(FILEINFO_MIME_TYPE)->file($path);
        $extension = UploadAllowlist::extensionFor($mime);

        throw_if($extension === null, UploadException::mimeNotAllowed($mime));

        return $team->addMedia($path)
            ->usingFileName(Str::ulid().'.'.$extension)
            ->usingName(pathinfo($originalName, PATHINFO_FILENAME))
            ->withCustomProperties([
                'team_id' => $team->getKey(),
                'uploaded_by' => $user->getKey(),
                'source' => $source->value,
                'original_name' => $originalName,
            ])
            ->toMediaCollection(MediaCollection::PendingUploads->value);
    }
}
```

- [ ] **Step 7: Wire config, disk, and models**

`config/media-library.php`: replace `'path_generator' => DefaultPathGenerator::class,` with `'path_generator' => UploadPathGenerator::class,` and add `use App\Support\Media\UploadPathGenerator;` to the imports. Remove the now-unused `DefaultPathGenerator` import.

`config/filesystems.php`, inside `'disks'` after `'public'`:

```php
        'media' => [
            'driver' => env('MEDIA_DRIVER', 'local'),
            'root' => storage_path('app/media'),
            'visibility' => env('MEDIA_VISIBILITY', 'private'),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('MEDIA_BUCKET', env('AWS_BUCKET')),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],
```

`.env.example`, after `FILESYSTEM_DISK=local`:

```
MEDIA_DISK=public
```

`app/Models/Company.php`: add after `getFilamentAvatarUrl()`:

```php
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::LOGO_MEDIA_COLLECTION)->useDisk('public');
    }
```

`app/Models/Team.php`: if the class does not yet implement `HasMedia` (it will once #606 lands), add `HasMedia` to the `implements` list, `use InteractsWithMedia;` to the trait list, and the imports `use Spatie\MediaLibrary\HasMedia;` and `use Spatie\MediaLibrary\InteractsWithMedia;`. Then add:

```php
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(MediaCollection::Logo->value)->useDisk('public');
    }
```

with `use App\Enums\MediaCollection;`. If #606 already added a `registerMediaCollections()`, fold the `useDisk('public')` into its `logo` registration instead of adding a second method.

`app/Models/People.php`, `Opportunity.php`, `Task.php`, `Note.php`: add `HasMedia` to `implements`, `use InteractsWithMedia;` in alphabetical position among the traits, plus the two imports. No collection registration; `custom-field-{code}` collections are created on demand.

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=MediaStorageTest`
Expected: PASS, 6 tests.

- [ ] **Step 9: Run the gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
git add -A
git commit -m "feat(media): store pending uploads on the team behind a uuid path"
```

---

### Task 2: Claim and release through the value observer

**Files:**
- Create: `app/Support/Media/UploadClaims.php`
- Modify: `app/Observers/CustomFieldValueObserver.php:16-38`
- Test: `tests/Feature/Media/UploadClaimsTest.php`

**Interfaces:**
- Consumes: `MediaPaths::uuidFromPath()`, `MediaCollection::forCustomField()`, `MediaCollection::PendingUploads`
- Produces: `UploadClaims::sync(CustomFieldValue $value): void`, called from `CustomFieldValueObserver::saved()`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Media/UploadClaimsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Actions\Upload\StorePendingUpload;
use App\Enums\MediaCollection;
use App\Enums\UploadSource;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\User;
use App\Observers\CustomFieldValueObserver;
use App\Support\Media\UploadClaims;
use Illuminate\Support\Facades\Storage;
use Relaticle\CustomFields\Services\TenantContextService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(UploadClaims::class, CustomFieldValueObserver::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
    TenantContextService::setTenantId($this->team->getKey());
    $this->contract = CustomField::factory()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'note',
        'code' => 'contract',
        'name' => 'Contract',
        'type' => 'file-upload',
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $this->body = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'note')
        ->where('code', 'body')
        ->firstOrFail();
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

function pendingUpload(User $user, string $bytes, string $name): Media
{
    $path = tempnam(sys_get_temp_dir(), 'claim');
    file_put_contents($path, $bytes);

    return resolve(StorePendingUpload::class)->execute($user, $user->personalTeam(), $path, $name, UploadSource::Panel);
}

it('claims a pending upload onto the record when a file value references it', function (): void {
    $media = pendingUpload($this->user, pdfBytes(), 'a.pdf');
    $path = $media->getPathRelativeToRoot();
    $note = Note::factory()->create(['team_id' => $this->team->getKey()]);

    $note->saveCustomFieldValue($this->contract, $path);

    $media->refresh();
    expect($media->model_type)->toBe($note->getMorphClass())
        ->and($media->model_id)->toBe($note->getKey())
        ->and($media->collection_name)->toBe(MediaCollection::forCustomField('contract'))
        ->and($media->getPathRelativeToRoot())->toBe($path);
    Storage::disk('public')->assertExists($path);
});

it('releases the previous file when a file value is replaced', function (): void {
    $first = pendingUpload($this->user, pdfBytes(), 'a.pdf');
    $second = pendingUpload($this->user, pdfBytes(), 'b.pdf');
    $note = Note::factory()->create(['team_id' => $this->team->getKey()]);
    $note->saveCustomFieldValue($this->contract, $first->getPathRelativeToRoot());

    $note->saveCustomFieldValue($this->contract, $second->getPathRelativeToRoot());

    expect(Media::query()->find($first->getKey()))->toBeNull()
        ->and($second->refresh()->model_id)->toBe($note->getKey());
    Storage::disk('public')->assertMissing($first->getPathRelativeToRoot());
});

it('releases the file when a file value is cleared', function (): void {
    $media = pendingUpload($this->user, pdfBytes(), 'a.pdf');
    $note = Note::factory()->create(['team_id' => $this->team->getKey()]);
    $note->saveCustomFieldValue($this->contract, $media->getPathRelativeToRoot());

    $note->saveCustomFieldValue($this->contract, null);

    expect(Media::query()->find($media->getKey()))->toBeNull();
});

it('never claims another team\'s pending upload', function (): void {
    $stranger = User::factory()->withPersonalTeam()->create();
    $foreign = pendingUpload($stranger, pdfBytes(), 'a.pdf');
    $note = Note::factory()->create(['team_id' => $this->team->getKey()]);

    $note->saveCustomFieldValue($this->contract, $foreign->getPathRelativeToRoot());

    expect($foreign->refresh()->collection_name)->toBe(MediaCollection::PendingUploads->value)
        ->and($foreign->model_id)->toBe($stranger->personalTeam()->getKey());
});

it('claims every image a rich editor body references and releases the ones it drops', function (): void {
    $kept = pendingUpload($this->user, onePixelPng(), 'kept.png');
    $dropped = pendingUpload($this->user, onePixelPng(), 'dropped.png');
    $note = Note::factory()->create(['team_id' => $this->team->getKey()]);
    $note->saveCustomFieldValue($this->body, "<p><img data-id=\"{$kept->uuid}\" src=\"x\"><img data-id=\"{$dropped->uuid}\" src=\"y\"></p>");

    expect($kept->refresh()->collection_name)->toBe(MediaCollection::forCustomField('body'))
        ->and($dropped->refresh()->model_id)->toBe($note->getKey());

    $note->saveCustomFieldValue($this->body, "<p><img data-id=\"{$kept->uuid}\" src=\"x\"></p>");

    expect(Media::query()->find($dropped->getKey()))->toBeNull()
        ->and(Media::query()->find($kept->getKey()))->not->toBeNull();
});

it('deletes claimed media with the record', function (): void {
    $media = pendingUpload($this->user, pdfBytes(), 'a.pdf');
    $note = Note::factory()->create(['team_id' => $this->team->getKey()]);
    $note->saveCustomFieldValue($this->contract, $media->getPathRelativeToRoot());

    $note->forceDelete();

    expect(Media::query()->find($media->getKey()))->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=UploadClaimsTest`
Expected: FAIL, the first test reports `collection_name` still `pending-uploads`.

- [ ] **Step 3: Create `UploadClaims`**

`app/Support/Media/UploadClaims.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Enums\CustomFieldType;
use App\Enums\MediaCollection;
use App\Models\CustomFieldValue;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class UploadClaims
{
    public function __construct(private MediaPaths $paths) {}

    public function sync(CustomFieldValue $value): void
    {
        $field = $value->customField;

        // @phpstan-ignore identical.alwaysFalse (the customField relation can resolve to null for an orphaned value row)
        if ($field === null) {
            return;
        }

        $referenced = match ($field->type) {
            CustomFieldType::FILE_UPLOAD->value => $this->fileUuids($value->getValue()),
            CustomFieldType::RICH_EDITOR->value => $this->imageUuids($value->getValue()),
            default => null,
        };

        if ($referenced === null) {
            return;
        }

        $entity = $value->entity;

        if (! $entity instanceof HasMedia) {
            return;
        }

        $collection = MediaCollection::forCustomField($field->code);
        $teamId = (string) $value->tenant_id;

        Media::query()
            ->where('custom_properties->team_id', $teamId)
            ->where('collection_name', MediaCollection::PendingUploads->value)
            ->whereIn('uuid', $referenced)
            ->get()
            ->each(function (Media $media) use ($entity, $collection): void {
                $media->model()->associate($entity);
                $media->collection_name = $collection;
                $media->save();
            });

        $entity->media()
            ->where('collection_name', $collection)
            ->whereNotIn('uuid', $referenced)
            ->get()
            ->each(fn (Media $media): ?bool => $media->delete());
    }

    /** @return list<string> */
    private function fileUuids(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        $uuid = $this->paths->uuidFromPath($value);

        return $uuid === null ? [] : [$uuid];
    }

    /** @return list<string> */
    private function imageUuids(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        preg_match_all('/data-id="([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})"/', $value, $matches);

        return array_values(array_unique($matches[1]));
    }
}
```

- [ ] **Step 4: Call it from the observer**

In `app/Observers/CustomFieldValueObserver.php`, add `UploadClaims $uploadClaims` to the constructor and replace `saved()`:

```php
    public function __construct(
        private EnsureTagOptionsExist $ensureTagOptionsExist,
        private UploadClaims $uploadClaims,
    ) {}

    public function saved(CustomFieldValue $value): void
    {
        if ($value->wasRecentlyCreated || $value->wasChanged(['string_value', 'text_value'])) {
            $this->uploadClaims->sync($value);
        }

        // Only multi-value fields (tags-input et al.) store an array in json_value;
        // scalar-typed fields leave it blank. Short-circuit before loading the
        // customField relation so ordinary custom-field saves incur no extra query.
        if (blank($value->json_value)) {
            return;
        }

        $field = $value->customField;

        // @phpstan-ignore identical.alwaysFalse (the customField relation can resolve to null for an orphaned value row)
        if ($field === null) {
            return;
        }

        $this->ensureTagOptionsExist->execute($field, $value->json_value);
    }
```

Add `use App\Support\Media\UploadClaims;`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=UploadClaimsTest`
Expected: PASS, 6 tests. Then `php artisan test --compact --filter=CustomFieldValueObserver` to confirm the activity-log tests still pass.

- [ ] **Step 6: Run the gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
git add -A
git commit -m "feat(media): claim pending uploads onto the record that references them"
```

---

### Task 3: Media-backed `file-upload` custom field

**Files:**
- Create: `app/Filament/CustomFields/FileUploadFieldType.php`, `FileUploadComponent.php`, `FileEntry.php`, `FileColumn.php`
- Modify: `app/Providers/AppServiceProvider.php:516-519`, `config/custom-fields.php:72`
- Test: `tests/Feature/Filament/App/Resources/CustomFieldFileUploadTest.php`

**Interfaces:**
- Consumes: `StorePendingUpload::execute()`, `MediaPaths::find()`, `MediaCollection::PendingUploads`, `UploadAllowlist::MIME_TYPES`
- Produces: the `file-upload` type registered under key `file-upload`; a stored value is `uploads/{uuid}/{file_name}`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Filament/App/Resources/CustomFieldFileUploadTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\MediaCollection;
use App\Filament\CustomFields\FileColumn;
use App\Filament\CustomFields\FileEntry;
use App\Filament\CustomFields\FileUploadComponent;
use App\Filament\CustomFields\FileUploadFieldType;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(FileUploadFieldType::class, FileUploadComponent::class, FileEntry::class, FileColumn::class);

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
    $this->contract = CustomField::factory()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'note',
        'code' => 'contract',
        'name' => 'Contract',
        'type' => 'file-upload',
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
});

it('offers the file-upload type', function (): void {
    expect(Relaticle\CustomFields\Facades\CustomFieldsType::getFieldType('file-upload'))->not->toBeNull();
});

it('stores a panel upload as pending media and claims it when the note is created', function (): void {
    livewire(ManageNotes::class)
        ->callAction('create', [
            'title' => 'With contract',
            'custom_fields' => ['contract' => UploadedFile::fake()->createWithContent('contract.pdf', pdfBytes())],
        ])
        ->assertHasNoActionErrors();

    $note = Note::query()->where('title', 'With contract')->firstOrFail();
    $path = $note->getCustomFieldValue($this->contract);
    $media = Media::query()->where('collection_name', MediaCollection::forCustomField('contract'))->firstOrFail();

    expect($path)->toBe($media->getPathRelativeToRoot())
        ->and($media->model_id)->toBe($note->getKey())
        ->and($media->getCustomProperty('original_name'))->toBe('contract.pdf');
    Storage::disk('public')->assertExists($path);
});

it('rejects a type the allowlist refuses', function (): void {
    livewire(ManageNotes::class)
        ->callAction('create', [
            'title' => 'With svg',
            'custom_fields' => ['contract' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')],
        ])
        ->assertHasActionErrors(['custom_fields.contract']);

    expect(Note::query()->where('title', 'With svg')->exists())->toBeFalse();
});

it('shows the stored file name as a link on the record', function (): void {
    $note = Note::factory()->create(['team_id' => $this->team->getKey()]);
    $media = $this->team->addMediaFromString(pdfBytes())
        ->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $note->saveCustomFieldValue($this->contract, $media->getPathRelativeToRoot());

    livewire(ManageNotes::class)
        ->mountAction(TestAction::make('view')->table($note))
        ->assertSee('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->assertSee($media->refresh()->getUrl());
});

it('releases the file when it is removed from the form', function (): void {
    $note = Note::factory()->create(['team_id' => $this->team->getKey()]);
    $media = $this->team->addMediaFromString(pdfBytes())
        ->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $note->saveCustomFieldValue($this->contract, $media->getPathRelativeToRoot());

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('edit')->table($note), ['custom_fields' => ['contract' => null]])
        ->assertHasNoActionErrors();

    expect(Media::query()->find($media->getKey()))->toBeNull()
        ->and($note->refresh()->getCustomFieldValue($this->contract))->toBeNull();
});
```

If `ManageNotes` names its table view or edit action differently, take the names from `app/Filament/Resources/NoteResource/Pages/ManageNotes.php` and `app/Filament/Resources/NoteResource.php` and keep the assertions.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=CustomFieldFileUploadTest`
Expected: FAIL, `getFieldType('file-upload')` is null because the type is disabled.

- [ ] **Step 3: Create the form component**

`app/Filament/CustomFields/FileUploadComponent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Actions\Upload\StorePendingUpload;
use App\Enums\MediaCollection;
use App\Enums\UploadSource;
use App\Exceptions\UploadException;
use App\Models\Team;
use App\Models\User;
use App\Support\Media\MediaPaths;
use App\Support\Media\UploadAllowlist;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractFormComponent;
use Relaticle\CustomFields\Models\CustomField;

final readonly class FileUploadComponent extends AbstractFormComponent
{
    public function create(CustomField $customField): Field
    {
        return FileUpload::make($customField->getFieldName())
            ->disk(config('media-library.disk_name'))
            ->acceptedFileTypes(array_keys(UploadAllowlist::MIME_TYPES))
            ->maxSize((int) (UploadAllowlist::MAX_BYTES / 1024))
            ->downloadable()
            ->openable()
            ->previewable()
            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file, FileUpload $component): string => $this->store($file, $component))
            ->getUploadedFileUsing(fn (string $file): ?array => $this->describe($file))
            ->deleteUploadedFileUsing(fn (string $file): null => $this->discardPending($file));
    }

    private function store(TemporaryUploadedFile $file, FileUpload $component): string
    {
        $user = auth()->user();
        $team = Filament::getTenant();

        abort_unless($user instanceof User && $team instanceof Team, 403);

        try {
            return resolve(StorePendingUpload::class)
                ->execute($user, $team, $file->getRealPath(), $file->getClientOriginalName(), UploadSource::Panel)
                ->getPathRelativeToRoot();
        } catch (UploadException $exception) {
            throw ValidationException::withMessages([$component->getStatePath() => $exception->getMessage()]);
        }
    }

    /** @return array{name: string, size: int, type: ?string, url: ?string}|null */
    private function describe(string $file): ?array
    {
        $media = $this->find($file);

        if ($media === null) {
            return null;
        }

        return [
            'name' => $media->file_name,
            'size' => (int) $media->size,
            'type' => $media->mime_type,
            'url' => $media->getUrl(),
        ];
    }

    private function discardPending(string $file): null
    {
        $media = $this->find($file);

        if ($media?->collection_name === MediaCollection::PendingUploads->value) {
            $media->delete();
        }

        return null;
    }

    private function find(string $file): ?\Spatie\MediaLibrary\MediaCollections\Models\Media
    {
        $team = Filament::getTenant();

        if (! $team instanceof Team) {
            return null;
        }

        return resolve(MediaPaths::class)->find((string) $team->getKey(), $file);
    }
}
```

Import `Spatie\MediaLibrary\MediaCollections\Models\Media` and use the short name in the `find()` return type. `discardPending()` deletes only pending rows on purpose: a claimed file is released by `UploadClaims` when the saved value drops it, so cancelling the form keeps the record intact.

If PHPStan's `EloquentWriteOutsideActionRule` flags `$media->delete()` in this `App\Filament` class, move `discardPending` into a new `app/Actions/Upload/DiscardPendingUpload.php` with `execute(Team $team, string $path): void` holding the same body, and call it from the closure.

- [ ] **Step 4: Create the field type, entry and column**

`app/Filament/CustomFields/FileUploadFieldType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use Relaticle\CustomFields\FieldTypeSystem\BaseFieldType;
use Relaticle\CustomFields\FieldTypeSystem\FieldSchema;

final class FileUploadFieldType extends BaseFieldType
{
    public function configure(): FieldSchema
    {
        return FieldSchema::file()
            ->key('file-upload')
            ->label(__('custom-fields::custom-fields.field_types.file_upload'))
            ->icon('heroicon-o-paper-clip')
            ->formComponent(FileUploadComponent::class)
            ->tableColumn(FileColumn::class)
            ->infolistEntry(FileEntry::class)
            ->priority(17)
            ->searchable()
            ->defaultValidationRules([]);
    }
}
```

The package type carried `defaultValidationRules(['file'])` and two settings capabilities. Both are dropped: the value is a path string, the allowlist and size gate live in `StorePendingUpload`, and the API rule arrives in Task 7.

`app/Filament/CustomFields/FileEntry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Support\Media\MediaPaths;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractInfolistEntry;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\CustomFields\Models\CustomField;

final class FileEntry extends AbstractInfolistEntry
{
    public function make(CustomField $customField): Entry
    {
        return TextEntry::make($customField->getFieldName())
            ->label($customField->name)
            ->state(fn (HasCustomFields&Model $record): ?string => $record->getCustomFieldValue($customField))
            ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : basename($state))
            ->url(fn (?string $state, Model $record): ?string => $state === null
                ? null
                : resolve(MediaPaths::class)->find((string) $record->getAttribute('team_id'), $state)?->getUrl())
            ->openUrlInNewTab();
    }
}
```

`app/Filament/CustomFields/FileColumn.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Support\Media\MediaPaths;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractTableColumn;
use Relaticle\CustomFields\Filament\Integration\Concerns\Tables\ConfiguresColumnLabel;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\CustomFields\Models\CustomField;

final class FileColumn extends AbstractTableColumn
{
    use ConfiguresColumnLabel;

    public function make(CustomField $customField): Column
    {
        $column = TextColumn::make($customField->getFieldName());

        $this->configureLabel($column, $customField);

        return $column
            ->getStateUsing(fn (HasCustomFields&Model $record): ?string => $record->getCustomFieldValue($customField))
            ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : basename($state))
            ->url(fn (?string $state, Model $record): ?string => $state === null
                ? null
                : resolve(MediaPaths::class)->find((string) $record->getAttribute('team_id'), $state)?->getUrl())
            ->openUrlInNewTab();
    }
}
```

- [ ] **Step 5: Register the type and enable it**

`app/Providers/AppServiceProvider.php`, in the `CustomFieldsType::register([...])` call, add `'file-upload' => FileUploadFieldType::class,` and the import `use App\Filament\CustomFields\FileUploadFieldType;`.

`config/custom-fields.php:72`: change `->disabled(['file-upload'])` to `->disabled([])`. If #695 has already landed, the line reads `->disabled(['file-upload', 'markdown-editor'])`; make it `->disabled(['markdown-editor'])`.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=CustomFieldFileUploadTest`
Expected: PASS, 5 tests.

- [ ] **Step 7: Run the gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
git add -A
git commit -m "feat(custom-fields): back the file-upload type with media rows"
```

---

### Task 4: Rich editor attachments through the provider

Prerequisite: #695 merged and `origin/main` merged into this branch. `app/Filament/CustomFields/RichEditorFieldType.php` must exist.

**Files:**
- Create: `app/Support/Media/RichContentAttachments.php`, `app/Filament/CustomFields/RichContentEntry.php`
- Create: `app/Console/Commands/BackfillRichEditorAttachmentsCommand.php`
- Modify: `app/Filament/CustomFields/RichEditorFieldType.php` (the `formComponent` closure and the infolist entry)
- Test: `tests/Feature/Filament/App/Resources/RichEditorAttachmentTest.php`, `tests/Feature/Media/BackfillRichEditorAttachmentsCommandTest.php`

**Interfaces:**
- Consumes: `StorePendingUpload::execute()`, `MediaPaths::findByUuid()`, `UploadClaims` (via the observer)
- Produces: `RichContentAttachments::forTeam(string $teamId): self`, `->saveUploadedFileAttachment(TemporaryUploadedFile): string`, `->getFileAttachmentUrl(mixed): ?string`, `->render(string $html): string`, `->rewriteImageSources(string $html): string`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Filament/App/Resources/RichEditorAttachmentTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\MediaCollection;
use App\Filament\CustomFields\RichContentEntry;
use App\Filament\CustomFields\RichEditorFieldType;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\User;
use App\Support\Media\RichContentAttachments;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Component;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(RichContentAttachments::class, RichContentEntry::class, RichEditorFieldType::class);

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake(FileUploadConfiguration::disk());
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
    $this->body = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'note')
        ->where('code', 'body')
        ->firstOrFail();
});

function noteBodyEditor(): RichEditor
{
    $page = livewire(ManageNotes::class)->mountAction('create')->instance();

    $editor = collect($page->getSchema($page->getMountedActionSchemaName())->getFlatComponents(withHidden: true))
        ->first(fn (Component $component): bool => $component instanceof RichEditor);

    expect($editor)->toBeInstanceOf(RichEditor::class);

    return $editor;
}

function livewireTemporaryPng(): TemporaryUploadedFile
{
    $name = TemporaryUploadedFile::generateHashNameWithOriginalNameEmbedded(
        UploadedFile::fake()->createWithContent('shot.png', onePixelPng()),
    );
    Storage::disk(FileUploadConfiguration::disk())->put(FileUploadConfiguration::path($name), onePixelPng());

    return new TemporaryUploadedFile($name, FileUploadConfiguration::disk());
}

it('saves a pasted image as pending media keyed by uuid', function (): void {
    $editor = noteBodyEditor();

    $id = $editor->saveUploadedFileAttachment(livewireTemporaryPng());

    $media = Media::query()->where('uuid', $id)->firstOrFail();
    expect($media->collection_name)->toBe(MediaCollection::PendingUploads->value)
        ->and($media->getCustomProperty('original_name'))->toBe('shot.png')
        ->and($editor->getFileAttachmentUrl($id))->toBe($media->getUrl())
        ->and($editor->getFileAttachmentsMaxSize())->toBe(10240);
});

it('resolves no url for an image another team owns', function (): void {
    $stranger = User::factory()->withPersonalTeam()->create();
    $foreign = $stranger->personalTeam()->addMediaFromString(onePixelPng())
        ->usingFileName('a.png')
        ->withCustomProperties(['team_id' => $stranger->personalTeam()->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);

    expect(noteBodyEditor()->getFileAttachmentUrl($foreign->uuid))->toBeNull();
});

it('claims the image when the note is created and renders it through the provider', function (): void {
    $id = noteBodyEditor()->saveUploadedFileAttachment(livewireTemporaryPng());

    livewire(ManageNotes::class)
        ->callAction('create', [
            'title' => 'With image',
            'custom_fields' => ['body' => "<p>Shot</p><p><img data-id=\"{$id}\" src=\"stale\" alt=\"shot\"></p>"],
        ])
        ->assertHasNoActionErrors();

    $note = Note::query()->where('title', 'With image')->firstOrFail();
    $media = Media::query()->where('uuid', $id)->firstOrFail();

    expect($media->model_id)->toBe($note->getKey())
        ->and($media->collection_name)->toBe(MediaCollection::forCustomField('body'));

    livewire(ManageNotes::class)
        ->mountAction(TestAction::make('view')->table($note))
        ->assertSee($media->getUrl());
});

it('releases an image the edited body no longer references', function (): void {
    $id = noteBodyEditor()->saveUploadedFileAttachment(livewireTemporaryPng());
    livewire(ManageNotes::class)
        ->callAction('create', ['title' => 'Edit me', 'custom_fields' => ['body' => "<p><img data-id=\"{$id}\" src=\"stale\"></p>"]])
        ->assertHasNoActionErrors();
    $note = Note::query()->where('title', 'Edit me')->firstOrFail();

    livewire(ManageNotes::class)
        ->callAction(TestAction::make('edit')->table($note), ['custom_fields' => ['body' => '<p>gone</p>']])
        ->assertHasNoActionErrors();

    expect(Media::query()->where('uuid', $id)->exists())->toBeFalse();
});

it('rewrites image sources from the media row for api readers', function (): void {
    $media = $this->team->addMediaFromString(onePixelPng())
        ->usingFileName('a.png')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);

    $html = RichContentAttachments::forTeam((string) $this->team->getKey())
        ->rewriteImageSources("<p><img src=\"https://old.test/x.png\" alt=\"a\" data-id=\"{$media->uuid}\"></p>");

    expect($html)->toBe("<p><img src=\"{$media->getUrl()}\" alt=\"a\" data-id=\"{$media->uuid}\"></p>");
});
```

`tests/Feature/Media/BackfillRichEditorAttachmentsCommandTest.php`:

```php
<?php

declare(strict_types=1);

use App\Console\Commands\BackfillRichEditorAttachmentsCommand;
use App\Enums\MediaCollection;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Relaticle\CustomFields\Services\TenantContextService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(BackfillRichEditorAttachmentsCommand::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
    TenantContextService::setTenantId($this->team->getKey());
    $this->body = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'note')
        ->where('code', 'body')
        ->firstOrFail();
    Storage::disk('public')->put('legacy.png', onePixelPng());
    $this->note = Note::factory()->create(['team_id' => $this->team->getKey()]);
    $this->note->saveCustomFieldValue($this->body, '<p><img src="https://app.test/storage/legacy.png" alt="old" data-id="legacy.png"></p>');
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

it('reports without writing by default', function (): void {
    $this->artisan('media:backfill-rich-editor-attachments')
        ->expectsOutputToContain('1 image(s) would be migrated')
        ->assertSuccessful();

    expect(Media::query()->count())->toBe(0);
});

it('creates a media row on the record and rewrites the image with --force', function (): void {
    $this->artisan('media:backfill-rich-editor-attachments --force')->assertSuccessful();

    $media = Media::query()->firstOrFail();
    $html = (string) $this->note->refresh()->getCustomFieldValue($this->body);

    expect($media->model_id)->toBe($this->note->getKey())
        ->and($media->collection_name)->toBe(MediaCollection::forCustomField('body'))
        ->and($media->getCustomProperty('team_id'))->toBe($this->team->getKey())
        ->and($html)->toContain("data-id=\"{$media->uuid}\"")
        ->and($html)->toContain("src=\"{$media->getUrl()}\"")
        ->and($html)->not->toContain('legacy.png');
});

it('is idempotent', function (): void {
    $this->artisan('media:backfill-rich-editor-attachments --force')->assertSuccessful();
    $this->artisan('media:backfill-rich-editor-attachments --force')
        ->expectsOutputToContain('0 image(s) migrated')
        ->assertSuccessful();

    expect(Media::query()->count())->toBe(1);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter='RichEditorAttachmentTest|BackfillRichEditorAttachmentsCommandTest'`
Expected: FAIL, `saveUploadedFileAttachment` stores to the public disk and returns a path, not a uuid.

- [ ] **Step 3: Create the provider**

`app/Support/Media/RichContentAttachments.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Actions\Upload\StorePendingUpload;
use App\Enums\UploadSource;
use App\Models\Team;
use App\Models\User;
use Filament\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use Filament\Forms\Components\RichEditor\RichContentAttribute;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class RichContentAttachments implements FileAttachmentProvider
{
    private const string IMAGE_PATTERN = '/<img\b[^>]*\bdata-id="([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})"[^>]*>/i';

    private function __construct(
        private readonly string $teamId,
        private readonly MediaPaths $paths,
    ) {}

    public static function forTeam(string $teamId): self
    {
        return new self($teamId, resolve(MediaPaths::class));
    }

    public function attribute(RichContentAttribute $attribute): static
    {
        return $this;
    }

    public function getFileAttachmentUrl(mixed $file): ?string
    {
        if (! is_string($file)) {
            return null;
        }

        return $this->paths->findByUuid($this->teamId, $file)?->getUrl();
    }

    public function saveUploadedFileAttachment(TemporaryUploadedFile $file): string
    {
        $user = auth()->user();
        $team = Team::query()->findOrFail($this->teamId);

        abort_unless($user instanceof User, 403);

        return resolve(StorePendingUpload::class)
            ->execute($user, $team, $file->getRealPath(), $file->getClientOriginalName(), UploadSource::Panel)
            ->uuid;
    }

    public function getDefaultFileAttachmentVisibility(): ?string
    {
        return null;
    }

    public function isExistingRecordRequiredToSaveNewFileAttachments(): bool
    {
        return false;
    }

    /** @param array<mixed> $exceptIds */
    public function cleanUpFileAttachments(array $exceptIds): void
    {
        // UploadClaims releases dropped images when the value is saved; Filament's
        // per-editor cleanup would delete another user's pending draft image.
    }

    public function render(string $html): string
    {
        return RichContentRenderer::make($html)->fileAttachmentProvider($this)->toHtml();
    }

    public function rewriteImageSources(string $html): string
    {
        return (string) preg_replace_callback(self::IMAGE_PATTERN, function (array $match): string {
            $url = $this->getFileAttachmentUrl($match[1]);

            if ($url === null) {
                return $match[0];
            }

            $tag = (string) preg_replace('/\ssrc="[^"]*"/i', '', $match[0]);

            return '<img src="'.e($url).'"'.substr($tag, 4);
        }, $html);
    }
}
```

- [ ] **Step 4: Create the infolist entry and wire the field type**

`app/Filament/CustomFields/RichContentEntry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\CustomFields;

use App\Support\Media\RichContentAttachments;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Filament\Integration\Base\AbstractInfolistEntry;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\CustomFields\Models\CustomField;

final class RichContentEntry extends AbstractInfolistEntry
{
    public function make(CustomField $customField): Entry
    {
        return TextEntry::make($customField->getFieldName())
            ->html()
            ->label($customField->name)
            ->state(function (HasCustomFields&Model $record) use ($customField): ?string {
                $value = $record->getCustomFieldValue($customField);

                if (! is_string($value) || $value === '') {
                    return null;
                }

                return RichContentAttachments::forTeam((string) $record->getAttribute('team_id'))->render($value);
            });
    }
}
```

In `app/Filament/CustomFields/RichEditorFieldType.php`, inside `configure()`:

1. Chain `->infolistEntry(RichContentEntry::class)` on the schema after `->formComponent(...)`.
2. Inside the `RichEditor::make(...)` chain, after `->fileAttachments(true)`, add:

```php
                ->fileAttachmentsMaxSize(10240)
                ->saveUploadedFileAttachmentUsing(fn (TemporaryUploadedFile $file): string => $this->attachments()->saveUploadedFileAttachment($file))
                ->getFileAttachmentUrlUsing(fn (mixed $file): ?string => $this->attachments()->getFileAttachmentUrl($file))
```

3. Add the method and imports:

```php
    private function attachments(): RichContentAttachments
    {
        return RichContentAttachments::forTeam((string) Filament::getTenant()?->getKey());
    }
```

```php
use App\Support\Media\RichContentAttachments;
use Filament\Facades\Filament;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
```

- [ ] **Step 5: Create the backfill command**

`app/Console/Commands/BackfillRichEditorAttachmentsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CustomFieldType;
use App\Enums\MediaCollection;
use App\Enums\UploadSource;
use App\Models\CustomFieldValue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;

#[Description('Move legacy rich editor images from bare public-disk paths into media rows on their records')]
#[Signature('media:backfill-rich-editor-attachments {--force : Write changes instead of reporting them}')]
final class BackfillRichEditorAttachmentsCommand extends Command
{
    private const string LEGACY_IMAGE = '/<img\b[^>]*\bdata-id="(?![0-9a-f]{8}-)([^"]+)"[^>]*>/i';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $migrated = 0;

        $values = CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->whereHas('customField', fn (Builder $query): Builder => $query->where('type', CustomFieldType::RICH_EDITOR->value))
            ->where('text_value', 'like', '%<img%')
            ->with('customField')
            ->get();

        foreach ($values as $value) {
            $html = (string) $value->text_value;

            if (preg_match_all(self::LEGACY_IMAGE, $html, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            $entity = $value->entity;

            if (! $entity instanceof HasMedia) {
                $this->warn("Value {$value->getKey()} has no media-capable record, skipped.");

                continue;
            }

            foreach ($matches as $match) {
                $legacyPath = $match[1];

                if (! Storage::disk('public')->exists($legacyPath)) {
                    $this->warn("Value {$value->getKey()}: {$legacyPath} is missing on the public disk, skipped.");

                    continue;
                }

                $this->info("Value {$value->getKey()}: migrating {$legacyPath}");
                $migrated++;

                if (! $force) {
                    continue;
                }

                $media = $entity->addMediaFromDisk($legacyPath, 'public')
                    ->preservingOriginal()
                    ->withCustomProperties([
                        'team_id' => $value->tenant_id,
                        'uploaded_by' => null,
                        'source' => UploadSource::Panel->value,
                        'original_name' => basename($legacyPath),
                    ])
                    ->toMediaCollection(MediaCollection::forCustomField($value->customField->code));

                $tag = (string) preg_replace('/\ssrc="[^"]*"/i', '', $match[0]);
                $tag = str_replace("data-id=\"{$legacyPath}\"", "data-id=\"{$media->uuid}\"", $tag);
                $tag = '<img src="'.e($media->getUrl()).'"'.substr($tag, 4);

                $html = str_replace($match[0], $tag, $html);
            }

            if ($force) {
                $value->setValue($html);
                $value->save();
            }
        }

        $this->comment($force ? "{$migrated} image(s) migrated." : "{$migrated} image(s) would be migrated. Re-run with --force to write.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='RichEditorAttachmentTest|BackfillRichEditorAttachmentsCommandTest'`
Expected: PASS, 8 tests. Also run `php artisan test --compact --filter=NoteResourceTest` to confirm #695's editor tests still pass.

- [ ] **Step 7: Run the gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
git add -A
git commit -m "feat(custom-fields): store rich editor images as media on the record"
```

---

### Task 5: `StoreAgentUpload` and `SsrfGuard::pinnedClient()`

**Files:**
- Create: `app/Actions/Upload/StoreAgentUpload.php`, `app/Support/Media/TemporaryUploads.php`
- Modify: `app/Services/Favicon/SsrfGuard.php`
- Test: `tests/Feature/Services/Favicon/SsrfGuardTest.php` (extend), `tests/Feature/Mcp/UploadToolsTest.php` (created here, extended in Task 6)

**Interfaces:**
- Consumes: `StorePendingUpload::execute()`, `UploadAllowlist`, `UploadException`
- Produces: `SsrfGuard::pinnedClient(string $url): PendingRequest`
- Produces: `StoreAgentUpload::execute(User $user, Team $team, array $input): Media` where `$input` is `array{source_url?: ?string, base64?: ?string, filename?: ?string, upload_id?: ?string}`
- Produces: `TemporaryUploads::NAME_PATTERN`, `TemporaryUploads::newName(string $filename): string`, `TemporaryUploads::path(string $upload): string`, `TemporaryUploads::disk(): FilesystemAdapter`, `TemporaryUploads::isValidName(string $upload): bool`

- [ ] **Step 1: Extend `SsrfGuardTest`**

Add `use Illuminate\Http\Client\PendingRequest;` to the imports of `tests/Feature/Services/Favicon/SsrfGuardTest.php`, then append:

```php
test('pinned client refuses plain http', function (): void {
    expect(fn (): PendingRequest => SsrfGuard::pinnedClient('http://example.com/file.pdf'))
        ->toThrow(SsrfGuardException::class, 'Only https URLs on port 443 are allowed');
});

test('pinned client refuses a non-default port', function (): void {
    expect(fn (): PendingRequest => SsrfGuard::pinnedClient('https://example.com:8443/file.pdf'))
        ->toThrow(SsrfGuardException::class, 'Only https URLs on port 443 are allowed');
});

test('pinned client refuses a private host', function (): void {
    expect(fn (): PendingRequest => SsrfGuard::pinnedClient('https://10.0.0.1/file.pdf'))
        ->toThrow(SsrfGuardException::class);
});

test('pinned client never follows redirects and pins the resolved address', function (): void {
    $client = SsrfGuard::pinnedClient('https://93.184.216.34/file.pdf');
    $options = (fn (): array => $this->options)->call($client);

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['timeout'])->toBe(30)
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['93.184.216.34:443:93.184.216.34']);
});
```

- [ ] **Step 2: Write the failing action tests**

`tests/Feature/Mcp/UploadToolsTest.php`:

```php
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
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

describe('StoreAgentUpload', function (): void {
    it('fetches a public https url into pending uploads', function (): void {
        Http::fake(['https://cdn.example.com/*' => Http::response(pdfBytes(), 200, ['Content-Type' => 'application/pdf'])]);

        $media = resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['source_url' => 'https://cdn.example.com/brief.pdf']);

        expect($media->collection_name)->toBe(MediaCollection::PendingUploads->value)
            ->and($media->mime_type)->toBe('application/pdf')
            ->and($media->getCustomProperty('source'))->toBe('url')
            ->and($media->getCustomProperty('original_name'))->toBe('brief.pdf');
    });

    it('reports an unreachable url', function (): void {
        Http::fake(['https://cdn.example.com/*' => Http::response('', 404)]);

        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['source_url' => 'https://cdn.example.com/missing.pdf']))
            ->toThrow(UploadException::class, __('uploads.errors.unreachable'));
    });

    it('reports a url the guard refuses', function (): void {
        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['source_url' => 'http://169.254.169.254/latest']))
            ->toThrow(UploadException::class, __('uploads.errors.url_not_allowed'));
    });

    it('rejects a fetched body over 10 MB', function (): void {
        Http::fake(['https://cdn.example.com/*' => Http::response(str_repeat('a', 10 * 1024 * 1024 + 1), 200)]);

        expect(fn (): Media => resolve(StoreAgentUpload::class)->execute($this->user, $this->team, ['source_url' => 'https://cdn.example.com/huge.bin']))
            ->toThrow(UploadException::class, __('uploads.errors.too_large'));
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
        ]))->toThrow(UploadException::class, __('uploads.errors.too_large'));
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
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact --filter='SsrfGuardTest|UploadToolsTest'`
Expected: FAIL, `pinnedClient` and `StoreAgentUpload` undefined.

- [ ] **Step 4: Add `pinnedClient()` to `SsrfGuard`**

In `app/Services/Favicon/SsrfGuard.php` add after `redirectGuardOptions()`:

```php
    /**
     * A client for one-shot downloads that never follows a redirect and connects
     * to the address resolved here, so a DNS answer cannot change between the
     * check and the fetch (TOCTOU). https on 443 only.
     */
    public static function pinnedClient(string $url): PendingRequest
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $port = is_array($parts) ? ($parts['port'] ?? 443) : null;

        throw_unless($scheme === 'https' && $port === 443, SsrfGuardException::class, 'Only https URLs on port 443 are allowed');

        self::assertPublicHost($url);

        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $address = self::resolveAddresses($host)[0];
        $pinned = str_contains($address, ':') ? "[{$address}]" : $address;

        return Http::withOptions([
            'allow_redirects' => false,
            'connect_timeout' => 10,
            'timeout' => 30,
            'curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$pinned}"]],
            'progress' => static function (int $downloadTotal, int $downloaded): void {
                throw_if(max($downloadTotal, $downloaded) > UploadAllowlist::MAX_BYTES, UploadException::tooLarge());
            },
        ]);
    }
```

Add imports `use App\Exceptions\UploadException;` and `use App\Support\Media\UploadAllowlist;`.

- [ ] **Step 5: Create `TemporaryUploads` and `StoreAgentUpload`**

`app/Support/Media/TemporaryUploads.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Exceptions\UploadException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class TemporaryUploads
{
    public const string NAME_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}\.[a-z0-9]{2,5}$/';

    public const string DIRECTORY = 'tmp';

    public static function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) config('media-library.disk_name'));

        return $disk;
    }

    public static function newName(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        throw_unless(in_array($extension, UploadAllowlist::extensions(), true), UploadException::mimeNotAllowed($extension));

        return Str::ulid().'.'.$extension;
    }

    public static function path(string $upload): string
    {
        return self::DIRECTORY.'/'.$upload;
    }

    public static function isValidName(string $upload): bool
    {
        return preg_match(self::NAME_PATTERN, $upload) === 1;
    }
}
```

`app/Actions/Upload/StoreAgentUpload.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Enums\UploadSource;
use App\Exceptions\SsrfGuardException;
use App\Exceptions\UploadException;
use App\Models\Team;
use App\Models\User;
use App\Services\Favicon\SsrfGuard;
use App\Support\Media\TemporaryUploads;
use App\Support\Media\UploadAllowlist;
use Illuminate\Http\Client\ConnectionException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class StoreAgentUpload
{
    private const int MAX_BASE64_BYTES = 5 * 1024 * 1024;

    public function __construct(private StorePendingUpload $store) {}

    /**
     * @param  array{source_url?: ?string, base64?: ?string, filename?: ?string, upload_id?: ?string}  $input
     */
    public function execute(User $user, Team $team, array $input): Media
    {
        $temp = (string) tempnam(sys_get_temp_dir(), 'agent-upload');

        try {
            [$name, $source] = $this->materialise($input, $temp);

            $media = $this->store->execute($user, $team, $temp, $name, $source);

            if ($source === UploadSource::SignedPut) {
                TemporaryUploads::disk()->delete(TemporaryUploads::path((string) $input['upload_id']));
            }

            return $media;
        } finally {
            @unlink($temp);
        }
    }

    /**
     * @param  array{source_url?: ?string, base64?: ?string, filename?: ?string, upload_id?: ?string}  $input
     * @return array{0: string, 1: UploadSource}
     */
    private function materialise(array $input, string $temp): array
    {
        if (filled($input['source_url'] ?? null)) {
            return [$this->fetch((string) $input['source_url'], $temp), UploadSource::Url];
        }

        if (filled($input['base64'] ?? null)) {
            return [$this->decode((string) $input['base64'], (string) ($input['filename'] ?? 'upload'), $temp), UploadSource::Base64];
        }

        return [$this->takeTemporary((string) ($input['upload_id'] ?? ''), $temp), UploadSource::SignedPut];
    }

    private function fetch(string $url, string $temp): string
    {
        try {
            $response = SsrfGuard::pinnedClient($url)->get($url);
        } catch (SsrfGuardException) {
            throw UploadException::urlNotAllowed();
        } catch (ConnectionException) {
            throw UploadException::unreachable();
        }

        throw_unless($response->successful(), UploadException::unreachable());

        $body = $response->body();

        throw_if(strlen($body) > UploadAllowlist::MAX_BYTES, UploadException::tooLarge());

        file_put_contents($temp, $body);

        $name = basename((string) parse_url($url, PHP_URL_PATH));

        return $name === '' ? 'download' : $name;
    }

    private function decode(string $base64, string $filename, string $temp): string
    {
        $bytes = base64_decode($base64, strict: true);

        throw_if($bytes === false, UploadException::invalidBase64());
        throw_if(strlen($bytes) > self::MAX_BASE64_BYTES, UploadException::tooLarge());

        file_put_contents($temp, $bytes);

        return $filename;
    }

    private function takeTemporary(string $upload, string $temp): string
    {
        throw_unless(TemporaryUploads::isValidName($upload), UploadException::notFound());

        $disk = TemporaryUploads::disk();
        $path = TemporaryUploads::path($upload);

        throw_unless($disk->exists($path), UploadException::notFound());

        $stream = $disk->readStream($path);

        throw_if($stream === null, UploadException::notFound());

        file_put_contents($temp, $stream);

        return $upload;
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='SsrfGuardTest|UploadToolsTest'`
Expected: PASS.

- [ ] **Step 7: Run the gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
git add -A
git commit -m "feat(uploads): accept agent files by url, base64, or signed put"
```

---

### Task 6: Signed PUT route, the two MCP tools, and the purge

**Files:**
- Create: `app/Http/Controllers/Mcp/ReceiveUploadController.php`
- Create: `app/Mcp/Tools/CreateUploadUrlTool.php`, `app/Mcp/Tools/UploadFileTool.php`, `app/Mcp/Tools/Concerns/LimitsUploads.php`
- Create: `app/Console/Commands/PurgePendingUploadsCommand.php`
- Modify: `routes/ai.php`, `app/Mcp/Servers/RelaticleServer.php`, `bootstrap/app.php:178-190`
- Test: `tests/Feature/Mcp/UploadToolsTest.php` (extend), `tests/Feature/Media/PurgePendingUploadsCommandTest.php`

**Interfaces:**
- Consumes: `StoreAgentUpload::execute()`, `TemporaryUploads::*`, `UploadAllowlist::isImage()`
- Produces: route `mcp.uploads.receive` (`PUT {mcpPath}/uploads/{upload}`, `signed`)
- Produces: tools `create-upload-url` → `{upload_id, url, headers, expires_at}` and `upload-file` → `{file_id, path, url, mime_type, size, suggested_markdown}`
- Produces: `app:purge-pending-uploads` hourly

- [ ] **Step 1: Extend `UploadToolsTest`**

Append to `tests/Feature/Mcp/UploadToolsTest.php` (add the imports `App\Mcp\Servers\RelaticleServer`, `App\Mcp\Tools\CreateUploadUrlTool`, `App\Mcp\Tools\UploadFileTool`, `Illuminate\Support\Facades\RateLimiter`, `Illuminate\Support\Facades\URL`, and add both tools to `mutates(...)`):

```php
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
    it('stores the body under tmp and answers 204', function (): void {
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
```

`tests/Feature/Media/PurgePendingUploadsCommandTest.php`:

```php
<?php

declare(strict_types=1);

use App\Console\Commands\PurgePendingUploadsCommand;
use App\Enums\MediaCollection;
use App\Models\User;
use App\Support\Media\TemporaryUploads;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(PurgePendingUploadsCommand::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->team = User::factory()->withPersonalTeam()->create()->personalTeam();
});

it('removes pending media and temp files older than a day, keeps the rest', function (): void {
    $this->travelTo(now()->subHours(25));
    $old = $this->team->addMediaFromString(pdfBytes())->usingFileName('old.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $oldTemp = TemporaryUploads::newName('old.pdf');
    TemporaryUploads::disk()->put(TemporaryUploads::path($oldTemp), pdfBytes());
    touch(TemporaryUploads::disk()->path(TemporaryUploads::path($oldTemp)), now()->getTimestamp());

    $this->travelBack();
    $fresh = $this->team->addMediaFromString(pdfBytes())->usingFileName('fresh.pdf')
        ->withCustomProperties(['team_id' => $this->team->getKey()])
        ->toMediaCollection(MediaCollection::PendingUploads->value);
    $freshTemp = TemporaryUploads::newName('fresh.pdf');
    TemporaryUploads::disk()->put(TemporaryUploads::path($freshTemp), pdfBytes());

    $this->artisan('app:purge-pending-uploads')
        ->expectsOutputToContain('Purged 1 pending upload(s) and 1 temp file(s).')
        ->assertSuccessful();

    expect(Media::query()->find($old->getKey()))->toBeNull()
        ->and(Media::query()->find($fresh->getKey()))->not->toBeNull();
    TemporaryUploads::disk()->assertMissing(TemporaryUploads::path($oldTemp));
    TemporaryUploads::disk()->assertExists(TemporaryUploads::path($freshTemp));
});

it('is scheduled hourly on one server without overlap', function (): void {
    $event = collect(resolve(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn (Illuminate\Console\Scheduling\Event $event): bool => str_contains((string) $event->command, 'app:purge-pending-uploads'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter='UploadToolsTest|PurgePendingUploadsCommandTest'`
Expected: FAIL, the tool classes and route do not exist.

- [ ] **Step 3: Create the rate-limit concern and the tools**

`app/Mcp/Tools/Concerns/LimitsUploads.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\Team;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Response;

trait LimitsUploads
{
    private const int UPLOADS_PER_HOUR = 60;

    protected function denyIfUploadLimitReached(Team $team): ?Response
    {
        $key = "mcp-uploads:{$team->getKey()}";

        if (RateLimiter::tooManyAttempts($key, self::UPLOADS_PER_HOUR)) {
            return Response::error(__('uploads.errors.rate_limited'));
        }

        RateLimiter::hit($key, 3600);

        return null;
    }
}
```

`app/Mcp/Tools/CreateUploadUrlTool.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\UploadException;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasExplicitToolAnnotations;
use App\Mcp\Tools\Concerns\LimitsUploads;
use App\Models\User;
use App\Support\Media\TemporaryUploads;
use App\Support\Media\UploadAllowlist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\URL;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Title('Create Upload URL')]
#[Description('Get a short-lived signed URL to PUT a file body to (max 10 MB, pdf/doc/docx/jpeg/png/gif/webp). Then call upload-file with the returned upload_id to finish. Use upload-file directly with source_url or base64 when you can.')]
final class CreateUploadUrlTool extends Tool
{
    use ChecksTokenAbility;
    use HasExplicitToolAnnotations;
    use LimitsUploads;

    private const int EXPIRY_MINUTES = 5;

    protected function destructiveHint(): bool
    {
        return false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'filename' => $schema->string()->required()->description('The file name with its extension, e.g. "brief.pdf". Allowed extensions: '.implode(', ', UploadAllowlist::extensions()).'.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'upload_id' => $schema->string()->required(),
            'url' => $schema->string()->required(),
            'headers' => $schema->object()->required(),
            'expires_at' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('create')) instanceof Response) {
            return $denied;
        }

        /** @var User $user */
        $user = auth()->user();

        if (($limited = $this->denyIfUploadLimitReached($user->currentTeam)) instanceof Response) {
            return $limited;
        }

        $validated = $request->validate(['filename' => ['required', 'string', 'max:255']]);

        try {
            $uploadId = TemporaryUploads::newName($validated['filename']);
        } catch (UploadException $exception) {
            return Response::error($exception->getMessage());
        }

        $expiresAt = now()->addMinutes(self::EXPIRY_MINUTES);

        return Response::structured([
            'upload_id' => $uploadId,
            'url' => URL::temporarySignedRoute('mcp.uploads.receive', $expiresAt, ['upload' => $uploadId]),
            'headers' => ['Content-Type' => 'application/octet-stream'],
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }
}
```

`app/Mcp/Tools/UploadFileTool.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\Upload\StoreAgentUpload;
use App\Exceptions\UploadException;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasExplicitToolAnnotations;
use App\Mcp\Tools\Concerns\LimitsUploads;
use App\Models\User;
use App\Support\Media\UploadAllowlist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Title('Upload File')]
#[Description('Store a file in this workspace and get back the path to set on a file-upload custom field. Pass exactly one of: source_url (public https), base64 with filename (max 5 MB decoded), or upload_id from create-upload-url. Allowed types: pdf, doc, docx, jpeg, png, gif, webp; 10 MB max.')]
final class UploadFileTool extends Tool
{
    use ChecksTokenAbility;
    use HasExplicitToolAnnotations;
    use LimitsUploads;

    protected function destructiveHint(): bool
    {
        return false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'source_url' => $schema->string()->description('A public https URL to fetch. No redirects are followed.'),
            'base64' => $schema->string()->description('The file body, base64 encoded. Requires filename.'),
            'filename' => $schema->string()->description('The original file name, used with base64.'),
            'upload_id' => $schema->string()->description('The upload_id from create-upload-url after the PUT succeeded.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'file_id' => $schema->string()->required(),
            'path' => $schema->string()->required(),
            'url' => $schema->string()->required(),
            'mime_type' => $schema->string()->required(),
            'size' => $schema->integer()->required(),
            'suggested_markdown' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('create')) instanceof Response) {
            return $denied;
        }

        /** @var User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'source_url' => ['nullable', 'string', 'url:https', 'max:2048', 'required_without_all:base64,upload_id', 'prohibits:base64,upload_id'],
            'base64' => ['nullable', 'string', 'required_with:filename', 'prohibits:upload_id'],
            'filename' => ['nullable', 'string', 'max:255', 'required_with:base64'],
            'upload_id' => ['nullable', 'string', 'max:64'],
        ]);

        if (($limited = $this->denyIfUploadLimitReached($user->currentTeam)) instanceof Response) {
            return $limited;
        }

        try {
            $media = resolve(StoreAgentUpload::class)->execute($user, $user->currentTeam, $validated);
        } catch (UploadException $exception) {
            return Response::error($exception->getMessage());
        }

        $name = (string) $media->getCustomProperty('original_name', $media->file_name);
        $url = $media->getUrl();

        return Response::structured([
            'file_id' => $media->uuid,
            'path' => $media->getPathRelativeToRoot(),
            'url' => $url,
            'mime_type' => (string) $media->mime_type,
            'size' => (int) $media->size,
            'suggested_markdown' => UploadAllowlist::isImage((string) $media->mime_type) ? "![{$name}]({$url})" : "[{$name}]({$url})",
        ]);
    }
}
```

Register both in `app/Mcp/Servers/RelaticleServer.php` `$tools` after `ListCustomFieldsTool::class`, with imports.

- [ ] **Step 4: Create the receiver and the route**

`app/Http/Controllers/Mcp/ReceiveUploadController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use App\Support\Media\TemporaryUploads;
use App\Support\Media\UploadAllowlist;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ReceiveUploadController
{
    public function __invoke(Request $request, string $upload): Response
    {
        abort_unless(TemporaryUploads::isValidName($upload), Response::HTTP_NOT_FOUND);

        $length = $request->header('Content-Length');

        abort_if(! is_numeric($length) || (int) $length < 1, Response::HTTP_LENGTH_REQUIRED);
        abort_if((int) $length > UploadAllowlist::MAX_BYTES, Response::HTTP_REQUEST_ENTITY_TOO_LARGE);

        $disk = TemporaryUploads::disk();
        $path = TemporaryUploads::path($upload);
        $body = $request->getContent(asResource: true);

        $disk->writeStream($path, $body);

        if ((int) $disk->size($path) > UploadAllowlist::MAX_BYTES) {
            $disk->delete($path);

            abort(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return response()->noContent();
    }
}
```

In `routes/ai.php`, add the import `use App\Http\Controllers\Mcp\ReceiveUploadController;` and, directly above the `if ($mcpDomain) {` block:

```php
$uploadPath = rtrim($mcpPath, '/').'/uploads/{upload}';
$registerUploadRoute = static fn () => Route::put($uploadPath, ReceiveUploadController::class)
    ->middleware(['signed', 'throttle:60,1'])
    ->name('mcp.uploads.receive');
```

Then call `$registerUploadRoute();` inside the `Route::domain($mcpDomain)->group(...)` closure (add `$registerUploadRoute` to its `use` list) and again in the `else` branch. The route stays outside the `$mcpMiddleware` stack on purpose: the caller of a PUT is a bare HTTP client holding a 5-minute signed URL, not an authenticated MCP session.

- [ ] **Step 5: Create the purge command and schedule it**

`app/Console/Commands/PurgePendingUploadsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MediaCollection;
use App\Support\Media\TemporaryUploads;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Description('Delete pending uploads and signed-put temp files nobody claimed within a day')]
#[Signature('app:purge-pending-uploads {--hours=24 : Age after which an unclaimed upload is deleted}')]
final class PurgePendingUploadsCommand extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subHours((int) $this->option('hours'));

        $media = Media::query()
            ->where('collection_name', MediaCollection::PendingUploads->value)
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($media as $item) {
            $this->info("Deleting pending upload {$item->uuid}");
            $item->delete();
        }

        $disk = TemporaryUploads::disk();
        $temps = 0;

        foreach ($disk->files(TemporaryUploads::DIRECTORY) as $file) {
            if ($disk->lastModified($file) >= $cutoff->getTimestamp()) {
                continue;
            }

            $this->info("Deleting temp file {$file}");
            $disk->delete($file);
            $temps++;
        }

        $this->comment("Purged {$media->count()} pending upload(s) and {$temps} temp file(s).");

        return self::SUCCESS;
    }
}
```

In `bootstrap/app.php` `withSchedule`, after the `import:cleanup` line:

```php
        $schedule->command('app:purge-pending-uploads')->hourly()->withoutOverlapping()->onOneServer();
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='UploadToolsTest|PurgePendingUploadsCommandTest|RelaticleServerTest|ToolAnnotationsTest|RegistryManifestTest'`
Expected: PASS. If `RegistryManifestTest` or `ToolAnnotationsTest` snapshot the tool list, update the expected list with the two new tools.

- [ ] **Step 7: Run the gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
git add -A
git commit -m "feat(mcp): add create-upload-url and upload-file tools"
```

---

### Task 7: `StoredUploadPath` rule and `{path, url}` reads

**Files:**
- Create: `app/Rules/StoredUploadPath.php`
- Modify: `app/Rules/ValidCustomFields.php:56-64`, `app/Http/Resources/V1/Concerns/FormatsCustomFields.php:32-50`, `app/Mcp/Resources/Concerns/ResolvesEntitySchema.php:109`, `lang/en/validation.php:30-38`
- Test: `tests/Feature/Mcp/CustomFieldWritesTest.php` (extend), `tests/Feature/Api/V1/CustomFieldWritesApiTest.php` (extend)

**Interfaces:**
- Consumes: `MediaPaths::find()`, `MediaCollection::*`, `RichContentAttachments::rewriteImageSources()`
- Produces: `StoredUploadPath::__construct(string $teamId, CustomField $field, string|int|null $entityId = null)`

- [ ] **Step 1: Extend the MCP write tests**

Append to `tests/Feature/Mcp/CustomFieldWritesTest.php` (add `App\Rules\StoredUploadPath` to `mutates(...)`, plus imports `App\Enums\MediaCollection`, `App\Mcp\Tools\Note\CreateNoteTool`, `App\Mcp\Tools\Note\GetNoteTool`, `App\Mcp\Tools\Note\UpdateNoteTool`, `App\Mcp\Tools\UploadFileTool`, `App\Models\Note`, `Illuminate\Support\Facades\Storage`, `Spatie\MediaLibrary\MediaCollections\Models\Media`):

```php
function uploadedPath(User $user, string $filename = 'brief.pdf'): string
{
    RelaticleServer::actingAs($user)
        ->tool(UploadFileTool::class, ['base64' => base64_encode(pdfBytes()), 'filename' => $filename])
        ->assertOk();

    return Media::query()->latest('id')->firstOrFail()->getPathRelativeToRoot();
}

describe('file-upload values', function (): void {
    beforeEach(function (): void {
        Storage::fake('public');
        $this->contract = CustomField::factory()->create([
            'tenant_id' => $this->team->getKey(),
            'entity_type' => 'note',
            'code' => 'contract',
            'name' => 'Contract',
            'type' => 'file-upload',
            'validation_rules' => [],
            'active' => true,
            'system_defined' => false,
        ]);
    });

    it('sets a file field from an upload-file path and claims the media', function (): void {
        $path = uploadedPath($this->user);

        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'Signed', 'custom_fields' => ['contract' => $path]])
            ->assertOk()
            ->assertSee('"path"')
            ->assertSee('"url"');

        $note = Note::query()->where('title', 'Signed')->firstOrFail();
        $media = Media::query()->where('collection_name', MediaCollection::forCustomField('contract'))->firstOrFail();

        expect($media->model_id)->toBe($note->getKey())
            ->and($note->getCustomFieldValue($this->contract))->toBe($path);
    });

    it('accepts the record\'s current value on update', function (): void {
        $path = uploadedPath($this->user);
        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'Keep', 'custom_fields' => ['contract' => $path]])
            ->assertOk();
        $note = Note::query()->where('title', 'Keep')->firstOrFail();

        RelaticleServer::actingAs($this->user)
            ->tool(UpdateNoteTool::class, ['id' => $note->getKey(), 'title' => 'Kept', 'custom_fields' => ['contract' => $path]])
            ->assertOk();

        expect(Media::query()->where('collection_name', MediaCollection::forCustomField('contract'))->count())->toBe(1);
    });

    it('rejects another workspace\'s upload', function (): void {
        $stranger = User::factory()->withPersonalTeam()->create();
        $path = uploadedPath($stranger);

        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'Nope', 'custom_fields' => ['contract' => $path]])
            ->assertHasErrors()
            ->assertSee('Contract: pass a path returned by the upload-file tool');
    });

    it('rejects a tmp path, a traversal path, and a missing file', function (): void {
        foreach (['tmp/01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf', '../.env', 'uploads/00000000-0000-0000-0000-000000000000/x.pdf'] as $bad) {
            RelaticleServer::actingAs($this->user)
                ->tool(CreateNoteTool::class, ['title' => 'Bad', 'custom_fields' => ['contract' => $bad]])
                ->assertHasErrors();
        }

        expect(Note::query()->where('title', 'Bad')->exists())->toBeFalse();
    });

    it('reads a file field as path and url', function (): void {
        $path = uploadedPath($this->user);
        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'Read', 'custom_fields' => ['contract' => $path]])
            ->assertOk();
        $note = Note::query()->where('title', 'Read')->firstOrFail();
        $media = Media::query()->where('collection_name', MediaCollection::forCustomField('contract'))->firstOrFail();

        RelaticleServer::actingAs($this->user)
            ->tool(GetNoteTool::class, ['id' => $note->getKey()])
            ->assertOk()
            ->assertSee($media->uuid)
            ->assertSee('"url"');
    });

    it('rewrites rich editor image sources on read', function (): void {
        $media = $this->team->addMediaFromString(onePixelPng())->usingFileName('a.png')
            ->withCustomProperties(['team_id' => $this->team->getKey()])
            ->toMediaCollection(MediaCollection::PendingUploads->value);

        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'Img', 'custom_fields' => ['body' => "<p><img src=\"stale\" data-id=\"{$media->uuid}\"></p>"]])
            ->assertOk()
            ->assertSee($media->uuid)
            ->assertDontSee('stale');
    });
});
```

Take the `UpdateNoteTool` id parameter name from `app/Mcp/Tools/Note/UpdateNoteTool.php` if it is not `id`.

- [ ] **Step 2: Extend the REST tests**

Append to `tests/Feature/Api/V1/CustomFieldWritesApiTest.php` (add imports `App\Enums\MediaCollection`, `App\Models\Note`, `Illuminate\Support\Facades\Storage`, `Spatie\MediaLibrary\MediaCollections\Models\Media`):

```php
describe('file-upload values over rest', function (): void {
    beforeEach(function (): void {
        Storage::fake('public');
        $this->contract = CustomField::factory()->create([
            'tenant_id' => $this->team->getKey(),
            'entity_type' => 'note',
            'code' => 'contract',
            'name' => 'Contract',
            'type' => 'file-upload',
            'validation_rules' => [],
            'active' => true,
            'system_defined' => false,
        ]);
        $this->pending = $this->team->addMediaFromString(pdfBytes())->usingFileName('01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf')
            ->withCustomProperties(['team_id' => $this->team->getKey()])
            ->toMediaCollection(MediaCollection::PendingUploads->value);
    });

    it('stores an owned pending path and returns path and url', function (): void {
        $path = $this->pending->getPathRelativeToRoot();

        $this->postJson('/api/v1/notes', ['title' => 'Rest file', 'custom_fields' => ['contract' => $path]])
            ->assertCreated()
            ->assertJsonPath('data.attributes.custom_fields.contract.path', $path)
            ->assertJsonPath('data.attributes.custom_fields.contract.url', $this->pending->refresh()->getUrl());
    });

    it('returns 422 for a path this workspace does not own', function (): void {
        $this->postJson('/api/v1/notes', ['title' => 'Rest bad', 'custom_fields' => ['contract' => 'uploads/00000000-0000-0000-0000-000000000000/x.pdf']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['custom_fields.contract']);
    });

    it('returns null for an empty file field', function (): void {
        $note = Note::factory()->create(['team_id' => $this->team->getKey()]);
        $note->saveCustomFieldValue($this->contract, null);

        $this->getJson("/api/v1/notes/{$note->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.attributes.custom_fields.contract', null);
    });
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact --filter='CustomFieldWritesTest|CustomFieldWritesApiTest'`
Expected: FAIL, the create call stores the path string but nothing rejects the foreign path and reads return a bare string.

- [ ] **Step 4: Create the rule and wire it**

`lang/en/validation.php`, inside `'custom_field'`:

```php
        'upload_path' => ':field: pass a path returned by the upload-file tool.',
```

`app/Rules/StoredUploadPath.php`:

```php
<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\MediaCollection;
use App\Support\Media\MediaPaths;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Relaticle\CustomFields\Models\CustomField;

final readonly class StoredUploadPath implements ValidationRule
{
    public function __construct(
        private string $teamId,
        private CustomField $field,
        private string|int|null $entityId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $media = is_string($value) ? resolve(MediaPaths::class)->find($this->teamId, $value) : null;

        if ($media === null) {
            $fail(__('validation.custom_field.upload_path', ['field' => $this->field->name]));

            return;
        }

        if ($media->collection_name === MediaCollection::PendingUploads->value) {
            return;
        }

        $ownsCurrentValue = $this->entityId !== null
            && (string) $media->model_id === (string) $this->entityId
            && $media->collection_name === MediaCollection::forCustomField($this->field->code);

        if ($ownsCurrentValue) {
            return;
        }

        $fail(__('validation.custom_field.upload_path', ['field' => $this->field->name]));
    }
}
```

In `app/Rules/ValidCustomFields.php::toRules()`, after the `RECORD` block inside the foreach:

```php
                if ($customField->type === CustomFieldType::FILE_UPLOAD->value) {
                    $ruleKey = "custom_fields.{$customField->code}";
                    $rules[$ruleKey] = array_merge($rules[$ruleKey] ?? [], [
                        new StoredUploadPath($this->tenantId, $customField, $this->ignoreEntityId),
                    ]);
                }
```

- [ ] **Step 5: Shape the reads**

In `app/Http/Resources/V1/Concerns/FormatsCustomFields.php::resolveFieldValue()`, before the `RECORD` check:

```php
        if ($customField->type === CustomFieldType::FILE_UPLOAD->value) {
            return $this->resolveFileValue($fieldValue, $rawValue);
        }

        if ($customField->type === CustomFieldType::RICH_EDITOR->value && is_string($rawValue)) {
            return RichContentAttachments::forTeam((string) $fieldValue->tenant_id)->rewriteImageSources($rawValue);
        }
```

and add:

```php
    /**
     * @return array{path: string, url: ?string}|null
     */
    private function resolveFileValue(CustomFieldValue $fieldValue, mixed $rawValue): ?array
    {
        if (! is_string($rawValue) || $rawValue === '') {
            return null;
        }

        $media = resolve(MediaPaths::class)->find((string) $fieldValue->tenant_id, $rawValue);

        return ['path' => $rawValue, 'url' => $media?->getUrl()];
    }
```

with imports `App\Support\Media\MediaPaths` and `App\Support\Media\RichContentAttachments`.

In `app/Mcp/Resources/Concerns/ResolvesEntitySchema.php:109`, change the `FILE_UPLOAD` example to `'uploads/8f2c1d4e-2b6a-4f0e-9d3c-1a2b3c4d5e6f/01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf'` and the format to `'path returned by the upload-file tool; read back as {path, url}'`. Grep `app/Mcp/Resources` for a `usage` string that lists tools and add `upload-file` to it if one exists.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='CustomFieldWritesTest|CustomFieldWritesApiTest|CustomFieldsApiTest|SchemaResourcesTest'`
Expected: PASS.

- [ ] **Step 7: Run the gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
git add -A
git commit -m "feat(custom-fields): validate file-upload values against owned media"
```

---

### Task 8: One switch to a private disk

**Files:**
- Create: `app/Support/Media/MediaUrlGenerator.php`, `app/Http/Controllers/Media/ShowMediaController.php`
- Modify: `config/media-library.php` (`url_generator`), `routes/web.php`
- Test: `tests/Feature/Media/PrivateDiskTest.php`

**Interfaces:**
- Consumes: `UploadAllowlist::isImage()`, the `media` disk from Task 1
- Produces: route `media.show` (`GET /media/{media:uuid}`, `signed`)

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Media/PrivateDiskTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\MediaCollection;
use App\Http\Controllers\Media\ShowMediaController;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\UploadFileTool;
use App\Models\Company;
use App\Models\User;
use App\Support\Media\MediaUrlGenerator;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(MediaUrlGenerator::class, ShowMediaController::class);

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
        ->and($media->getUrl())->toContain('signature=');

    $this->get($media->getUrl())
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="'.$media->file_name.'"');
});

it('renders images inline on a private disk', function (): void {
    usePrivateMediaDisk();
    RelaticleServer::actingAs($this->user)
        ->tool(UploadFileTool::class, ['base64' => base64_encode(onePixelPng()), 'filename' => 'pixel.png'])
        ->assertOk();
    $media = Media::query()->latest('id')->firstOrFail();

    $this->get($media->getUrl())
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename="'.$media->file_name.'"');
});

it('refuses an unsigned or expired private url', function (): void {
    usePrivateMediaDisk();
    $media = uploadPdf($this->user);

    $this->get(route('media.show', ['media' => $media->uuid]))->assertForbidden();

    $this->travel(31)->minutes();
    $this->get($media->getUrl())->assertForbidden();
});

it('keeps company logos on the public disk regardless of the switch', function (): void {
    usePrivateMediaDisk();
    $company = Company::factory()->create(['team_id' => $this->team->getKey()]);

    $logo = $company->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(MediaCollection::Logo->value);

    expect($logo->disk)->toBe('public')
        ->and($logo->getUrl())->not->toContain('signature=');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=PrivateDiskTest`
Expected: FAIL on the private-disk cases, `getUrl()` returns a disk URL with no signature.

- [ ] **Step 3: Create the URL generator, controller and route**

`app/Support/Media/MediaUrlGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Media;

use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\Support\UrlGenerator\DefaultUrlGenerator;

final class MediaUrlGenerator extends DefaultUrlGenerator
{
    private const int SIGNED_URL_MINUTES = 30;

    public function getUrl(): string
    {
        if (config("filesystems.disks.{$this->media->disk}.visibility") === 'public') {
            return parent::getUrl();
        }

        return URL::temporarySignedRoute('media.show', now()->addMinutes(self::SIGNED_URL_MINUTES), ['media' => $this->media->uuid]);
    }
}
```

`config/media-library.php`: `'url_generator' => MediaUrlGenerator::class,` with the import, dropping the unused `DefaultUrlGenerator` import.

`app/Http/Controllers/Media/ShowMediaController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Support\Media\UploadAllowlist;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ShowMediaController
{
    public function __invoke(Request $request, Media $media): StreamedResponse
    {
        return UploadAllowlist::isImage((string) $media->mime_type)
            ? $media->toInlineResponse($request)
            : $media->toResponse($request);
    }
}
```

`routes/web.php`, with the import:

```php
Route::get('/media/{media:uuid}', ShowMediaController::class)
    ->middleware('signed')
    ->name('media.show');
```

Place it outside any auth group; the signature is the credential.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='PrivateDiskTest|MediaStorageTest|CustomFieldFileUploadTest'`
Expected: PASS.

- [ ] **Step 5: Run the gates and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
git add -A
git commit -m "feat(media): serve private-disk files through a signed route"
```

---

### Task 9: Guard the rule

**Files:**
- Create: `.ai/rules/file-uploads.md`
- Modify: `.ai/rules/index.md`, `tests/Arch/ConventionsTest.php`
- Test: the arch test itself

- [ ] **Step 1: Write the rule file**

`.ai/rules/file-uploads.md`:

```markdown
# File uploads

Durable user files go through medialibrary with a named collection on the owning
model (`App\Enums\MediaCollection`). Two exemptions: import CSVs under
`storage/app/imports` (transient) and Jetstream profile photos (framework-owned).

- Every upload lands in the team's `pending-uploads` collection first
  (`App\Actions\Upload\StorePendingUpload`). `App\Support\Media\UploadClaims`
  moves it onto the record when a saved custom-field value references it.
- Never call `Media::move()`. It copies and deletes, changing `uuid` and path.
  Ownership changes are attribute writes on the existing row.
- `logo` collections stay on the public disk. Everything else follows `MEDIA_DISK`.
- Do not add a `FileUpload::make(` or configure rich editor attachments with
  `fileAttachmentsDisk(` / `fileAttachmentsDirectory(` outside
  `app/Filament/CustomFields/FileUploadComponent.php` and
  `app/Livewire/App/Profile/`. `tests/Arch/ConventionsTest.php` fails on it.
```

Add the row `| app/Filament/**, app/Livewire/**, app/Support/Media/**, app/Actions/Upload/** | .ai/rules/file-uploads.md |` to the table in `.ai/rules/index.md`.

- [ ] **Step 2: Add the arch test**

Append to `tests/Arch/ConventionsTest.php`:

```php
it('keeps new file uploads on medialibrary', function (): void {
    $root = dirname(__DIR__, 2);
    $allowed = [
        'app/Filament/CustomFields/FileUploadComponent.php',
        'app/Livewire/App/Profile/UpdateProfileInformation.php',
    ];
    $offenders = [];

    foreach ([$root.'/app', $root.'/packages'] as $directory) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/\.php$/',
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $relative = str_replace($root.'/', '', $file->getPathname());

            if (in_array($relative, $allowed, true)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/FileUpload::make\(|->fileAttachmentsDisk\(|->fileAttachmentsDirectory\(/', $source) === 1) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Durable uploads go through medialibrary (.ai/rules/file-uploads.md). Offending files: '.implode(', ', $offenders),
    );
});
```

- [ ] **Step 3: Run the arch test**

Run: `php artisan test --compact --filter='keeps new file uploads on medialibrary'`
Expected: PASS. If #606 has merged with a `FileUpload::make('logo_path')`, the test fails and names the file; that is the signal #606's rework has not landed, not a reason to widen `$allowed`.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "chore(rules): guard durable uploads behind medialibrary"
```

---

### Task 10: Whole-branch gate and browser pass

- [ ] **Step 1: Sync and run the full gate**

```bash
git fetch origin && git merge origin/main
vendor/bin/pint --test --parallel
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:type-coverage
composer test:pest:full
```

Expected: all green. `composer test:pest:full` is the merge gate; TIA replays cannot see the `travelTo` cases in the purge and private-disk tests.

- [ ] **Step 2: Browser pass with agent-browser**

Use the `agent-browser-relaticle` skill. With Horizon running and `QUEUE_CONNECTION=redis`:

1. Create a `file-upload` custom field named Contract on Notes in the panel settings.
2. Create a note, attach a PDF in Contract, save. Confirm the record page shows the file name as a link that opens, and `media` holds one row in `custom-field-contract` on the note.
3. Edit the note, paste a PNG into the body, save. Confirm the image renders on the record page and `media` holds a row in `custom-field-body` on the note.
4. Over MCP (`RelaticleServer` via the OAuth connector or a personal access token with `create`): call `upload-file` with a base64 PDF, then `update-note` with `custom_fields.contract` set to the returned `path`. Confirm the panel now shows the new file and the previous one is gone from `media` and disk.
5. Set `MEDIA_DISK=media` and `MEDIA_VISIBILITY=private` locally, restart, repeat step 2. Confirm the link is `/media/{uuid}?...signature=` and downloads the PDF; the pasted PNG still renders inline. Restore `MEDIA_DISK=public`.

Take light and dark screenshots of the record page for steps 2, 3 and 5.

- [ ] **Step 3: Deployment check**

A 5 MB base64 payload is a 7 MB request body. Confirm nginx `client_max_body_size` and PHP `post_max_size` on the MCP host allow at least 8 MB before release, and add `MEDIA_DISK=public` to the production env so the default is explicit.

- [ ] **Step 4: Follow-ups outside this branch**

- `php artisan media:backfill-rich-editor-attachments` (dry run), then `--force`, once after deploy: 15 values in production.
- Retarget #699 to `main` when the founder says so.
- Push: `git push origin HEAD:feat/agent-file-uploads`.
