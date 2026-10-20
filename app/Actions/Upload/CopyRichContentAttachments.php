<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Enums\CustomFieldType;
use App\Enums\MediaCollection;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaLookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class CopyRichContentAttachments
{
    public function __construct(private MediaLookup $lookup, private Filesystem $files) {}

    /**
     * @param  array<string, mixed>  $customFields
     * @return array<string, mixed>
     */
    public function execute(User $user, Model&HasCustomFields&HasMedia $source, array $customFields): array
    {
        abort_unless($user->can('view', $source), 403);

        $workspace = $source->getRelationValue('workspace');
        abort_unless($workspace instanceof Workspace, 403);

        // The HTML comes from the submitted form, so only a file the source itself owns is copied.
        $owned = $source->getMedia(MediaCollection::Attachments->value)->keyBy('uuid');
        $copies = [];

        foreach ($source->customFields()->where('type', CustomFieldType::RICH_EDITOR->value)->pluck('code') as $code) {
            $html = $customFields[$code] ?? null;

            if (! is_string($html)) {
                continue;
            }

            foreach ($this->lookup->attachmentUuids($html) as $uuid) {
                $original = $owned->get($uuid);

                if ($original instanceof Media) {
                    $copies[$uuid] ??= $this->copy($original, $workspace);
                    $html = str_ireplace($uuid, $copies[$uuid]->uuid, $html);
                }
            }

            $customFields[$code] = $html;
        }

        return $customFields;
    }

    private function copy(Media $original, Workspace $workspace): Media
    {
        $copy = $original->copy(
            $workspace,
            MediaCollection::PendingUploads->value,
            fileAdderCallback: fn (FileAdder $adder): FileAdder => $adder->withAttributes(['workspace_id' => $workspace->getKey()]),
        );

        // A rollback drops the row but not the stored file, and the purge only finds files by their row.
        DB::afterRollBack(function () use ($copy): void {
            $this->files->removeAllFiles($copy);
        });

        return $copy;
    }
}
