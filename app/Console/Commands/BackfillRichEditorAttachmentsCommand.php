<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CustomFieldType;
use App\Enums\MediaCollection;
use App\Enums\UploadSource;
use App\Models\CustomFieldValue;
use App\Support\Media\UploadAllowlist;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Throwable;

#[Description('Move legacy rich editor images from bare public-disk paths into media rows on their records')]
#[Signature('media:backfill-rich-editor-attachments {--force : Write changes instead of reporting them}')]
final class BackfillRichEditorAttachmentsCommand extends Command
{
    private const string IMAGE = '/<img\b[^>]*>/i';

    private const string LEGACY_ID = '/\bdata-id="(?![0-9a-f]{8}-[0-9a-f]{4}-)([A-Za-z0-9][A-Za-z0-9._-]*)"/i';

    private const string PUBLIC_DISK_SRC = '#\bsrc="[^"]*/storage/([A-Za-z0-9][A-Za-z0-9._-]*)"#i';

    public function handle(): int
    {
        $write = (bool) $this->option('force');
        $migrated = 0;

        $values = CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->whereHas('customField', fn (Builder $query): Builder => $query->withoutGlobalScopes()->where('type', CustomFieldType::RICH_EDITOR->value))
            ->where('text_value', 'like', '%<img%')
            ->with(['entity', 'customField' => fn (Relation $query): Relation => $query->withoutGlobalScopes()])
            ->lazyById();

        foreach ($values as $value) {
            try {
                $migrated += $this->migrateValue($value, $write);
            } catch (Throwable $exception) {
                // A throw here escapes the migration that queues this command, which then
                // never logs and re-runs on every container start.
                $this->warn("Value {$value->getKey()}: {$exception->getMessage()}, skipped.");
            }
        }

        $this->comment($write
            ? "{$migrated} image(s) migrated."
            : "{$migrated} image(s) would be migrated. Re-run with --force to write.");

        return self::SUCCESS;
    }

    private function migrateValue(CustomFieldValue $value, bool $write): int
    {
        $public = Storage::disk('public');
        $migrated = 0;
        $html = (string) $value->text_value;

        $legacy = [];

        preg_match_all(self::IMAGE, $html, $tags);

        foreach ($tags[0] as $tag) {
            $legacyPath = $this->legacyPath($tag);

            if ($legacyPath !== null) {
                $legacy[$tag] = $legacyPath;
            }
        }

        if ($legacy === []) {
            return 0;
        }

        $entity = $value->entity;

        if (! $entity instanceof HasMedia) {
            $this->warn("Value {$value->getKey()}: record is missing, skipped.");

            return 0;
        }

        foreach ($legacy as $tag => $legacyPath) {
            if (! $public->exists($legacyPath)) {
                $this->warn("Value {$value->getKey()}: {$legacyPath} is missing on the public disk, skipped.");

                continue;
            }

            $mime = (string) $public->mimeType($legacyPath);

            if (! in_array($mime, UploadAllowlist::mimeTypes(), true) || $public->size($legacyPath) > UploadAllowlist::maxBytes()) {
                $this->warn("Value {$value->getKey()}: {$legacyPath} is a [{$mime}] the attachments collection refuses, skipped.");

                continue;
            }

            $this->info("Value {$value->getKey()}: {$legacyPath}");

            $migrated++;

            if (! $write) {
                continue;
            }

            $media = $entity->addMediaFromDisk($legacyPath, 'public')
                ->preservingOriginal()
                ->usingName(basename($legacyPath))
                ->withAttributes([
                    'workspace_id' => $value->getAttribute('tenant_id'),
                ])
                ->withCustomProperties(['source' => UploadSource::Panel->value])
                ->toMediaCollection(MediaCollection::Attachments->value);

            $rewritten = (string) preg_replace(['/\ssrc="[^"]*"/i', '/\sdata-id="[^"]*"/i'], '', $tag);
            $html = str_replace($tag, '<img data-id="'.$media->uuid.'"'.substr($rewritten, 4), $html);
        }

        if (! $write || $html === (string) $value->text_value) {
            return $migrated;
        }

        $value->text_value = $html;

        activity()->withoutLogging(fn (): bool => $value->save());

        return $migrated;
    }

    private function legacyPath(string $tag): ?string
    {
        if (str_contains($tag, 'data-id=')) {
            return preg_match(self::LEGACY_ID, $tag, $match) === 1 ? $match[1] : null;
        }

        return preg_match(self::PUBLIC_DISK_SRC, $tag, $match) === 1 ? $match[1] : null;
    }
}
